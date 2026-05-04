<?php

namespace Tests\Feature\ConnectedAccounts;

use App\Models\ConnectedGoogleAccount;
use App\Models\User;
use App\Services\Google\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            OAuthService::TOKEN_URL => Http::response([
                'access_token' => 'drive-access-token',
                'refresh_token' => 'drive-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file',
                'token_type' => 'Bearer',
            ]),
            OAuthService::USERINFO_URL => Http::response([
                'sub' => 'drive-google-sub-9999',
                'email' => 'extra-drive@example.com',
                'name' => 'Extra Drive Account',
            ]),
        ]);
    }

    public function test_callback_creates_drive_account_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $state = 'state-drive-1';
        Cache::put("google_oauth:drive:state:{$state}", ['user_id' => $user->id, 'next' => '/accounts'], now()->addMinutes(10));

        $response = $this->get("/api/v1/drive/oauth/callback?code=auth-code&state={$state}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:3000/accounts?drive_connected=1', $location);
        $this->assertStringContainsString('email=extra-drive%40example.com', $location);

        $this->assertSame(1, $user->connectedGoogleAccounts()->count());
        $account = $user->connectedGoogleAccounts()->first();
        $this->assertSame(ConnectedGoogleAccount::PURPOSE_DRIVE, $account->purpose);
        $this->assertSame('drive-google-sub-9999', $account->google_sub);
        $this->assertSame('drive-access-token', $account->access_token);
        $this->assertSame('drive-refresh-token', $account->refresh_token);

        // State is single-use.
        $this->assertNull(Cache::get("google_oauth:drive:state:{$state}"));
    }

    public function test_callback_with_invalid_state_does_not_create_account(): void
    {
        $this->get('/api/v1/drive/oauth/callback?code=x&state=does-not-exist')
            ->assertRedirect('http://localhost:3000/accounts?drive_error=invalid_state');

        $this->assertSame(0, ConnectedGoogleAccount::count());
    }

    public function test_callback_propagates_google_error(): void
    {
        $this->get('/api/v1/drive/oauth/callback?error=access_denied')
            ->assertRedirect('http://localhost:3000/accounts?drive_error=access_denied');
    }

    public function test_callback_refuses_attaching_primary_login_account(): void
    {
        $user = User::factory()->create(['google_sub' => 'drive-google-sub-9999']);
        $state = 'state-same';
        Cache::put("google_oauth:drive:state:{$state}", ['user_id' => $user->id, 'next' => '/accounts'], now()->addMinutes(10));

        $this->get("/api/v1/drive/oauth/callback?code=x&state={$state}")
            ->assertRedirect('http://localhost:3000/accounts?drive_error=primary_account_cannot_be_drive');

        $this->assertSame(0, ConnectedGoogleAccount::count());
    }

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $state = 'state-enc';
        Cache::put("google_oauth:drive:state:{$state}", ['user_id' => $user->id, 'next' => '/accounts'], now()->addMinutes(10));

        $this->get("/api/v1/drive/oauth/callback?code=x&state={$state}")->assertRedirect();

        $row = DB::table('connected_google_accounts')->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('drive-access-token', $row->access_token);
        $this->assertNotSame('drive-refresh-token', $row->refresh_token);
    }

    public function test_re_consent_updates_existing_row_in_place(): void
    {
        $user = User::factory()->create();
        $state1 = 'state-first';
        Cache::put("google_oauth:drive:state:{$state1}", ['user_id' => $user->id, 'next' => '/accounts'], now()->addMinutes(10));
        $this->get("/api/v1/drive/oauth/callback?code=c1&state={$state1}")->assertRedirect();

        $state2 = 'state-second';
        Cache::put("google_oauth:drive:state:{$state2}", ['user_id' => $user->id, 'next' => '/accounts'], now()->addMinutes(10));
        $this->get("/api/v1/drive/oauth/callback?code=c2&state={$state2}")->assertRedirect();

        $this->assertSame(1, $user->connectedGoogleAccounts()->count());
    }
}
