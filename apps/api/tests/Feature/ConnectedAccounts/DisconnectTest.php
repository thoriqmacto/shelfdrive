<?php

namespace Tests\Feature\ConnectedAccounts;

use App\Models\ConnectedGoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisconnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->deleteJson('/api/v1/accounts/1')->assertStatus(401);
    }

    public function test_owner_can_disconnect_drive_account(): void
    {
        $user = User::factory()->create();
        $account = $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub',
            'email' => 'a@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/accounts/{$account->id}")
            ->assertNoContent();

        $this->assertSame(0, ConnectedGoogleAccount::count());
    }

    public function test_foreign_account_returns_404_not_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $owner->connectedGoogleAccounts()->create([
            'google_sub' => 'sub',
            'email' => 'a@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/accounts/{$account->id}")
            ->assertStatus(404);

        $this->assertNotNull(ConnectedGoogleAccount::find($account->id));
    }

    public function test_cannot_disconnect_primary_login_account(): void
    {
        $user = User::factory()->create();
        $account = $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub-login',
            'email' => $user->email,
            'purpose' => ConnectedGoogleAccount::PURPOSE_LOGIN,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/accounts/{$account->id}")
            ->assertStatus(422);

        $this->assertNotNull(ConnectedGoogleAccount::find($account->id));
    }
}
