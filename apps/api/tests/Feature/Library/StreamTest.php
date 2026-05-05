<?php

namespace Tests\Feature\Library;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\Google\DriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDriveClient;
use Tests\TestCase;

class StreamTest extends TestCase
{
    use RefreshDatabase;

    private FakeDriveClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeDriveClient([]);
        $this->app->instance(DriveClient::class, $this->fake);
    }

    public function test_access_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/library/1/stream/access')->assertStatus(401);
    }

    public function test_access_owner_mints_token_and_stream_streams_bytes(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $token = $this->actingAs($user)
            ->postJson("/api/v1/library/{$file->id}/stream/access")
            ->assertOk()
            ->json('token');
        $this->assertNotEmpty($token);
        $this->logout();

        $response = $this->get("/api/v1/library/{$file->id}/stream?token=".urlencode($token));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame($file->drive_file_id, $this->fake->lastFileId);
    }

    public function test_stream_forwards_range_header_and_status(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $token = $this->actingAs($user)
            ->postJson("/api/v1/library/{$file->id}/stream/access")
            ->json('token');
        $this->logout();

        $response = $this->withHeaders(['Range' => 'bytes=0-1023'])
            ->get("/api/v1/library/{$file->id}/stream?token=".urlencode($token));
        $response->assertStatus(206);
        $response->assertHeader('Accept-Ranges', 'bytes');

        $this->assertSame('bytes=0-1023', $this->fake->lastRangeHeader);
    }

    public function test_stream_with_sanctum_guard_works_without_token(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->actingAs($user)
            ->get("/api/v1/library/{$file->id}/stream")
            ->assertOk();
    }

    public function test_access_foreign_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);

        $this->actingAs($intruder)
            ->postJson("/api/v1/library/{$file->id}/stream/access")
            ->assertStatus(404);
    }

    public function test_stream_rejects_unknown_token(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->get("/api/v1/library/{$file->id}/stream?token=does-not-exist")
            ->assertStatus(404);
    }

    public function test_stream_rejects_token_bound_to_other_file(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $a = $this->seedFile($user, $account);
        $b = $this->seedFile($user, $account);

        $token = $this->actingAs($user)
            ->postJson("/api/v1/library/{$a->id}/stream/access")
            ->json('token');
        $this->logout();

        // Same user, but the token was minted for $a, not $b.
        $this->get("/api/v1/library/{$b->id}/stream?token=".urlencode($token))
            ->assertStatus(404);
    }

    public function test_stream_token_is_single_use(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $token = $this->actingAs($user)
            ->postJson("/api/v1/library/{$file->id}/stream/access")
            ->json('token');
        $this->logout();

        $this->get("/api/v1/library/{$file->id}/stream?token=".urlencode($token))->assertOk();
        // Second use must fail — token was pulled from cache the first time.
        $this->get("/api/v1/library/{$file->id}/stream?token=".urlencode($token))->assertStatus(404);
    }

    public function test_stream_no_auth_returns_404(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->get("/api/v1/library/{$file->id}/stream")->assertStatus(404);
    }

    private function driveAccount(User $user): ConnectedGoogleAccount
    {
        return $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub-'.$user->id,
            'email' => 'drive@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);
    }

    private function seedFile(User $user, ConnectedGoogleAccount $account): DriveFile
    {
        $df = new DriveFile([
            'drive_file_id' => 'drive-id-'.uniqid('', true),
            'name' => 'book.pdf',
            'mime_type' => 'application/pdf',
            'format' => DriveFile::FORMAT_PDF,
            'trashed' => false,
        ]);
        $df->user_id = $user->id;
        $df->connected_account_id = $account->id;
        $df->save();

        return $df;
    }

    /**
     * Drop any previously-seeded auth state. `actingAs` persists across
     * requests within a test, so explicit logout is the only way to
     * exercise the token-only path after a sanctum-authed request.
     */
    private function logout(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
