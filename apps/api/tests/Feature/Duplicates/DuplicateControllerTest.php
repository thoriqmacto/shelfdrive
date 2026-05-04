<?php

namespace Tests\Feature\Duplicates;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\DuplicateGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/duplicates')->assertStatus(401);
    }

    public function test_index_lists_only_unresolved_groups_for_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $a = $this->seedGroup($alice, ['confidence' => 'exact']);
        $aResolved = $this->seedGroup($alice, ['confidence' => 'exact', 'resolved_at' => now()]);
        $bobGroup = $this->seedGroup($bob);

        $response = $this->actingAs($alice)->getJson('/api/v1/duplicates');
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($a->id, $response->json('data.0.id'));
    }

    public function test_index_orders_account_scope_then_confidence(): void
    {
        $user = User::factory()->create();

        $crossLikely = $this->seedGroup($user, ['scope' => 'cross_account', 'confidence' => 'likely']);
        $accountPossible = $this->seedGroup($user, ['scope' => 'account', 'confidence' => 'possible']);
        $accountExact = $this->seedGroup($user, ['scope' => 'account', 'confidence' => 'exact']);

        $ids = collect($this->actingAs($user)->getJson('/api/v1/duplicates')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame(
            [$accountExact->id, $accountPossible->id, $crossLikely->id],
            $ids,
        );
    }

    public function test_resolve_records_canonical_and_marks_resolved(): void
    {
        $user = User::factory()->create();
        $group = $this->seedGroup($user);
        [$keep, $drop] = $group->members()->with('driveFile')->get()->all();

        $this->actingAs($user)
            ->postJson("/api/v1/duplicates/{$group->id}/resolve", [
                'canonical_drive_file_id' => $keep->drive_file_id,
            ])
            ->assertOk();

        $group->refresh();
        $this->assertNotNull($group->resolved_at);
        $this->assertSame($keep->drive_file_id, $group->canonical_drive_file_id);

        $this->assertNull($keep->driveFile->fresh()->removed_from_library_at);
        $this->assertNull($drop->driveFile->fresh()->removed_from_library_at);
    }

    public function test_resolve_with_remove_others_marks_non_canonical(): void
    {
        $user = User::factory()->create();
        $group = $this->seedGroup($user);
        [$keep, $drop] = $group->members()->with('driveFile')->get()->all();

        $this->actingAs($user)
            ->postJson("/api/v1/duplicates/{$group->id}/resolve", [
                'canonical_drive_file_id' => $keep->drive_file_id,
                'remove_others_from_library' => true,
            ])
            ->assertOk();

        $this->assertNull($keep->driveFile->fresh()->removed_from_library_at);
        $this->assertNotNull($drop->driveFile->fresh()->removed_from_library_at);
    }

    public function test_resolve_rejects_canonical_not_in_group(): void
    {
        $user = User::factory()->create();
        $group = $this->seedGroup($user);

        $this->actingAs($user)
            ->postJson("/api/v1/duplicates/{$group->id}/resolve", [
                'canonical_drive_file_id' => 999_999,
            ])
            ->assertStatus(422);

        $group->refresh();
        $this->assertNull($group->resolved_at);
    }

    public function test_resolve_foreign_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $group = $this->seedGroup($owner);
        $member = $group->members()->first();

        $this->actingAs($intruder)
            ->postJson("/api/v1/duplicates/{$group->id}/resolve", [
                'canonical_drive_file_id' => $member->drive_file_id,
            ])
            ->assertStatus(404);
    }

    public function test_resolve_already_resolved_returns_422(): void
    {
        $user = User::factory()->create();
        $group = $this->seedGroup($user, ['resolved_at' => now()]);
        $member = $group->members()->first();

        $this->actingAs($user)
            ->postJson("/api/v1/duplicates/{$group->id}/resolve", [
                'canonical_drive_file_id' => $member->drive_file_id,
            ])
            ->assertStatus(422);
    }

    private function seedGroup(User $user, array $overrides = []): DuplicateGroup
    {
        $account = $user->connectedGoogleAccounts()->first()
            ?? $user->connectedGoogleAccounts()->create([
                'google_sub' => 'sub-'.$user->id,
                'email' => 'drive@example.com',
                'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
                'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
            ]);

        $f1 = $this->seedFile($user, $account, ['name' => 'a.pdf']);
        $f2 = $this->seedFile($user, $account, ['name' => 'b.pdf']);

        $group = $user->duplicateGroups()->create(array_merge([
            'match_strategy' => DuplicateGroup::STRATEGY_MD5,
            'confidence' => DuplicateGroup::CONFIDENCE_EXACT,
            'scope' => DuplicateGroup::SCOPE_ACCOUNT,
        ], $overrides));

        $group->members()->create(['drive_file_id' => $f1->id]);
        $group->members()->create(['drive_file_id' => $f2->id]);

        return $group->fresh();
    }

    private function seedFile(User $user, ConnectedGoogleAccount $account, array $overrides = []): DriveFile
    {
        $df = new DriveFile(array_merge([
            'drive_file_id' => 'd-'.uniqid('', true),
            'name' => 'untitled.pdf',
            'mime_type' => 'application/pdf',
            'format' => DriveFile::FORMAT_PDF,
            'trashed' => false,
        ], $overrides));
        $df->user_id = $user->id;
        $df->connected_account_id = $account->id;
        $df->save();

        return $df;
    }
}
