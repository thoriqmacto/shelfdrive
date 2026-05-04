<?php

namespace Tests\Feature\Lists;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\EbookList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbookListControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_routes_return_401(): void
    {
        $this->getJson('/api/v1/lists')->assertStatus(401);
        $this->postJson('/api/v1/lists', ['name' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/lists/1')->assertStatus(401);
        $this->patchJson('/api/v1/lists/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/lists/1')->assertStatus(401);
    }

    public function test_index_lists_only_own_with_item_count(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->ebookLists()->create(['name' => 'Reading']);
        $alice->ebookLists()->create(['name' => 'Done']);
        $bob->ebookLists()->create(['name' => 'Bob list']);

        $response = $this->actingAs($alice)->getJson('/api/v1/lists');
        $response->assertOk()->assertJsonCount(2, 'data');
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Reading', 'Done'], $names);
        $this->assertSame(0, $response->json('data.0.item_count'));
    }

    public function test_store_creates_list_owned_by_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/lists', ['name' => 'Cardiology', 'description' => 'Refs.'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Cardiology');

        $this->assertSame(1, $user->ebookLists()->where('name', 'Cardiology')->count());
    }

    public function test_store_rejects_duplicate_name_per_user(): void
    {
        $user = User::factory()->create();
        $user->ebookLists()->create(['name' => 'Reading']);

        $this->actingAs($user)
            ->postJson('/api/v1/lists', ['name' => 'Reading'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_two_users_can_have_lists_with_the_same_name(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->ebookLists()->create(['name' => 'Reading']);

        $this->actingAs($bob)
            ->postJson('/api/v1/lists', ['name' => 'Reading'])
            ->assertStatus(201);
    }

    public function test_show_returns_items_in_position_order(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $list = $user->ebookLists()->create(['name' => 'Reading']);

        $f1 = $this->seedFile($user, $account);
        $f2 = $this->seedFile($user, $account);
        $f3 = $this->seedFile($user, $account);

        $list->items()->create(['drive_file_id' => $f3->id, 'position' => 3, 'added_at' => now()]);
        $list->items()->create(['drive_file_id' => $f1->id, 'position' => 1, 'added_at' => now()]);
        $list->items()->create(['drive_file_id' => $f2->id, 'position' => 2, 'added_at' => now()]);

        $response = $this->actingAs($user)->getJson("/api/v1/lists/{$list->id}");
        $response->assertOk()->assertJsonCount(3, 'data.items');

        $ids = collect($response->json('data.items'))->pluck('drive_file.id')->all();
        $this->assertSame([$f1->id, $f2->id, $f3->id], $ids);
    }

    public function test_show_foreign_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = $owner->ebookLists()->create(['name' => 'Private']);

        $this->actingAs($intruder)->getJson("/api/v1/lists/{$list->id}")->assertStatus(404);
    }

    public function test_update_renames_and_destroys_cascades_items(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $list = $user->ebookLists()->create(['name' => 'Old']);
        $f = $this->seedFile($user, $account);
        $list->items()->create(['drive_file_id' => $f->id, 'position' => 1, 'added_at' => now()]);

        $this->actingAs($user)
            ->patchJson("/api/v1/lists/{$list->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->actingAs($user)
            ->deleteJson("/api/v1/lists/{$list->id}")
            ->assertNoContent();

        $this->assertSame(0, EbookList::count());
        // Items cascade away with the list.
        $this->assertSame(0, \App\Models\EbookListItem::count());
        // The DriveFile itself is untouched.
        $this->assertNotNull($f->fresh());
    }

    public function test_update_foreign_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = $owner->ebookLists()->create(['name' => 'X']);

        $this->actingAs($intruder)
            ->patchJson("/api/v1/lists/{$list->id}", ['name' => 'pwned'])
            ->assertStatus(404);

        $this->assertSame('X', $list->fresh()->name);
    }

    public function test_destroy_foreign_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = $owner->ebookLists()->create(['name' => 'X']);

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/lists/{$list->id}")
            ->assertStatus(404);

        $this->assertNotNull($list->fresh());
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
