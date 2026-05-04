<?php

namespace Tests\Feature\Sync;

use App\Jobs\Drive\ScanDriveAccount;
use App\Models\ConnectedGoogleAccount;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_index_returns_401(): void
    {
        $this->getJson('/api/v1/sync')->assertStatus(401);
    }

    public function test_index_lists_only_runs_for_own_accounts(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceAcc = $this->driveAccount($alice);
        $bobAcc = $this->driveAccount($bob);
        $aliceAcc->syncRuns()->create(['kind' => SyncRun::KIND_MANUAL, 'status' => SyncRun::STATUS_SUCCESS]);
        $bobAcc->syncRuns()->create(['kind' => SyncRun::KIND_MANUAL, 'status' => SyncRun::STATUS_SUCCESS]);

        $this->actingAs($alice)
            ->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.connected_account_id', $aliceAcc->id);
    }

    public function test_run_dispatches_scan_for_owner(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        $this->actingAs($user)
            ->postJson("/api/v1/sync/{$account->id}/run")
            ->assertStatus(202)
            ->assertJsonPath('account_id', $account->id);

        Bus::assertDispatched(ScanDriveAccount::class, fn (ScanDriveAccount $j) => $j->connectedAccountId === $account->id);
    }

    public function test_run_returns_404_for_foreign_account(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);

        $this->actingAs($intruder)
            ->postJson("/api/v1/sync/{$account->id}/run")
            ->assertStatus(404);

        Bus::assertNothingDispatched();
    }

    public function test_run_refuses_login_purpose_account(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $login = $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub-login',
            'email' => $user->email,
            'purpose' => ConnectedGoogleAccount::PURPOSE_LOGIN,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/sync/{$login->id}/run")
            ->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    public function test_run_refuses_revoked_account(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $account = $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub',
            'email' => 'a@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_REVOKED,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/sync/{$account->id}/run")
            ->assertStatus(422);

        Bus::assertNothingDispatched();
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
}
