<?php

namespace Tests\Feature\Lists;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\EbookList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbookListItemControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/lists/1/items', ['drive_file_id' => 1])->assertStatus(401);
        $this->deleteJson('/api/v1/lists/1/items/1')->assertStatus(401);
    }

    public function test_add_appends_at_end_with_incrementing_position(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $list = $user->ebookLists()->create(['name' => 'Reading']);
        $f1 = $this->seedFile($user, $account);
        $f2 = $this->seedFile($user, $account);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/lists/{$list->id}/items", ['drive_file_id' => $f1->id])
            ->assertStatus(201)
            ->json('data');

        $second = $this->actingAs($user)
            ->postJson("/api/v1/lists/{$list->id}/items", ['drive_file_id' => $f2->id])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(1, $first['position']);
        $this->assertSame(2, $second['position']);
        $this->assertSame(2, $list->items()->count());
    }

    public function test_add_is_idempotent_when_file_already_on_list(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $list = $user->ebookLists()->create(['name' => 'Reading']);
        $f = $this->seedFile($user, $account);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/lists/{$list->id}/items", ['drive_file_id' => $f->id])
            ->assertStatus(201)
            ->json('data');

        $this->actingAs($user)
            ->postJson("/api/v1/lists/{$list->id}/items", ['drive_file_id' => $f->id])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $first['id']);

        $this->assertSame(1, $list->items()->count());
    }

    public function test_add_rejects_drive_file_owned_by_another_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobAccount = $this->driveAccount($bob);
        $bobsFile = $this->seedFile($bob, $bobAccount);

        $list = $alice->ebookLists()->create(['name' => 'Reading']);

        $this->actingAs($alice)
            ->postJson("/api/v1/lists/{$list->id}/items", ['drive_file_id' => $bobsFile->id])
            ->assertStatus(422);

        $this->assertSame(0, $list->items()->count());
    }

    public function test_add_to_foreign_list_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = $owner->ebookLists()->create(['name' => 'Private']);

        $this->actingAs($intruder)
            ->postJson("/api/v1/lists/{$list->id}/items", ['drive_file_id' => 1])
            ->assertStatus(404);
    }

    public function test_remove_owner_can_delete_item(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $list = $user->ebookLists()->create(['name' => 'Reading']);
        $f = $this->seedFile($user, $account);
        $item = $list->items()->create(['drive_file_id' => $f->id, 'position' => 1, 'added_at' => now()]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/lists/{$list->id}/items/{$item->id}")
            ->assertNoContent();

        $this->assertSame(0, $list->items()->count());
        // The DriveFile itself stays.
        $this->assertNotNull($f->fresh());
    }

    public function test_remove_foreign_list_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = $owner->ebookLists()->create(['name' => 'Private']);
        $account = $this->driveAccount($owner);
        $f = $this->seedFile($owner, $account);
        $item = $list->items()->create(['drive_file_id' => $f->id, 'position' => 1, 'added_at' => now()]);

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/lists/{$list->id}/items/{$item->id}")
            ->assertStatus(404);

        $this->assertNotNull($item->fresh());
    }

    public function test_remove_item_from_other_list_returns_404(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $listA = $user->ebookLists()->create(['name' => 'A']);
        $listB = $user->ebookLists()->create(['name' => 'B']);
        $f = $this->seedFile($user, $account);
        $item = $listA->items()->create(['drive_file_id' => $f->id, 'position' => 1, 'added_at' => now()]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/lists/{$listB->id}/items/{$item->id}")
            ->assertStatus(404);

        $this->assertNotNull($item->fresh());
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
            'drive_file_id' => 'd-'.uniqid('', true),
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
}
