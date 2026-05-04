<?php

namespace Tests\Feature\ConnectedAccounts;

use App\Models\User;
use App\Services\Google\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ConnectStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/drive/oauth/start')->assertStatus(401);
    }

    public function test_returns_drive_consent_url_with_correct_scopes_and_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/drive/oauth/start?next=/library');
        $response->assertOk()->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertStringStartsWith(OAuthService::AUTH_URL, $url);

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('test-drive-client-id', $query['client_id'] ?? null);
        $this->assertStringContainsString('drive.readonly', $query['scope'] ?? '');
        $this->assertStringContainsString('drive.file', $query['scope'] ?? '');
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame('consent', $query['prompt'] ?? null);

        $cached = Cache::get("google_oauth:drive:state:{$query['state']}");
        $this->assertSame($user->id, $cached['user_id']);
        $this->assertSame('/library', $cached['next']);
    }

    public function test_open_redirect_in_next_is_collapsed(): void
    {
        $user = User::factory()->create();
        $url = $this->actingAs($user)
            ->getJson('/api/v1/drive/oauth/start?next=https://evil.example.com/path')
            ->json('url');

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $cached = Cache::get("google_oauth:drive:state:{$query['state']}");
        $this->assertSame('/accounts', $cached['next']);
    }
}
