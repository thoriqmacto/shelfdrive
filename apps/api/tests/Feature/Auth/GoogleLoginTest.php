<?php

namespace Tests\Feature\Auth;

use App\Models\ConnectedGoogleAccount;
use App\Models\User;
use App\Services\Google\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            OAuthService::TOKEN_URL => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'expires_in' => 3600,
                'scope' => 'openid email profile',
                'token_type' => 'Bearer',
            ]),
            OAuthService::USERINFO_URL => Http::response([
                'sub' => 'google-sub-12345',
                'email' => 'jane@example.com',
                'email_verified' => true,
                'name' => 'Jane Doe',
                'picture' => 'https://example.com/avatar.png',
            ]),
        ]);
    }

    public function test_start_redirects_to_google_with_state(): void
    {
        $response = $this->get('/api/v1/auth/google/start?next=/library');

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith(OAuthService::AUTH_URL, $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('test-login-client-id', $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame('consent', $query['prompt'] ?? null);
        $this->assertSame('openid email profile', $query['scope'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);

        // State is stored in cache and round-trips the requested next path.
        $state = $query['state'];
        $cached = Cache::get("google_oauth:login:state:{$state}");
        $this->assertIsArray($cached);
        $this->assertSame('/library', $cached['next']);
    }

    public function test_start_rejects_open_redirect_in_next(): void
    {
        $response = $this->get('/api/v1/auth/google/start?next=https://evil.example.com/path');

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $cached = Cache::get("google_oauth:login:state:{$query['state']}");
        $this->assertSame('/dashboard', $cached['next']);
    }

    public function test_callback_creates_user_and_redirects_with_one_time_code(): void
    {
        $state = 'state-abc';
        Cache::put("google_oauth:login:state:{$state}", ['next' => '/dashboard'], now()->addMinutes(10));

        $response = $this->get("/api/v1/auth/google/callback?code=auth-code-1&state={$state}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:3000/login?google_code=', $location);
        $this->assertStringContainsString('next=%2Fdashboard', $location);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('google-sub-12345', $user->google_sub);
        $this->assertSame('Jane Doe', $user->name);
        $this->assertNotNull($user->email_verified_at);

        $account = ConnectedGoogleAccount::where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(ConnectedGoogleAccount::PURPOSE_LOGIN, $account->purpose);
        $this->assertSame(ConnectedGoogleAccount::STATUS_ACTIVE, $account->status);
        $this->assertSame('fake-access-token', $account->access_token);
        $this->assertSame('fake-refresh-token', $account->refresh_token);

        // State token is single-use.
        $this->assertNull(Cache::get("google_oauth:login:state:{$state}"));
    }

    public function test_callback_with_unknown_state_redirects_with_error(): void
    {
        $response = $this->get('/api/v1/auth/google/callback?code=x&state=does-not-exist');

        $response->assertRedirect('http://localhost:3000/login?google_error=invalid_state');
        $this->assertSame(0, User::count());
    }

    public function test_callback_propagates_google_error_param(): void
    {
        $response = $this->get('/api/v1/auth/google/callback?error=access_denied');

        $response->assertRedirect('http://localhost:3000/login?google_error=access_denied');
        $this->assertSame(0, User::count());
    }

    public function test_exchange_returns_sanctum_token_for_valid_code(): void
    {
        $user = User::factory()->create();
        $code = 'one-time-exchange-code';
        Cache::put("google_oauth:login:exchange:{$code}", ['user_id' => $user->id], now()->addSeconds(60));

        $response = $this->postJson('/api/v1/auth/google/exchange', ['code' => $code]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['id', 'email'], 'token', 'token_type', 'expires_at'])
            ->assertJsonPath('user.id', $user->id);

        // Code is single-use.
        $this->assertNull(Cache::get("google_oauth:login:exchange:{$code}"));
    }

    public function test_exchange_rejects_unknown_code(): void
    {
        $this->postJson('/api/v1/auth/google/exchange', ['code' => str_repeat('a', 48)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_callback_links_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'jane@example.com',
            'google_sub' => null,
        ]);

        $state = 'state-link';
        Cache::put("google_oauth:login:state:{$state}", ['next' => '/dashboard'], now()->addMinutes(10));

        $this->get("/api/v1/auth/google/callback?code=c&state={$state}")->assertRedirect();

        $existing->refresh();
        $this->assertSame('google-sub-12345', $existing->google_sub);
        $this->assertSame(1, User::count());
    }

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $state = 'state-enc';
        Cache::put("google_oauth:login:state:{$state}", ['next' => '/dashboard'], now()->addMinutes(10));

        $this->get("/api/v1/auth/google/callback?code=c&state={$state}")->assertRedirect();

        // Bypass the model cast and read the raw column to verify ciphertext.
        $row = DB::table('connected_google_accounts')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('fake-access-token', $row->access_token);
        $this->assertNotSame('fake-refresh-token', $row->refresh_token);
    }
}
