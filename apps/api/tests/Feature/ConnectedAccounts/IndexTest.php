<?php

namespace Tests\Feature\ConnectedAccounts;

use App\Models\ConnectedGoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/accounts')->assertStatus(401);
    }

    public function test_lists_only_own_accounts(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->connectedGoogleAccounts()->create([
            'google_sub' => 'alice-sub',
            'email' => 'alice@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);
        $bob->connectedGoogleAccounts()->create([
            'google_sub' => 'bob-sub',
            'email' => 'bob@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);

        $this->actingAs($alice)
            ->getJson('/api/v1/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'alice@example.com');
    }

    public function test_response_does_not_leak_tokens(): void
    {
        $user = User::factory()->create();
        $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub',
            'email' => 'a@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
            'access_token' => 'at-secret',
            'refresh_token' => 'rt-secret',
        ]);

        $body = $this->actingAs($user)->getJson('/api/v1/accounts')->json();

        $serialized = json_encode($body);
        $this->assertStringNotContainsString('at-secret', $serialized);
        $this->assertStringNotContainsString('rt-secret', $serialized);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('refresh_token', $serialized);
    }
}
