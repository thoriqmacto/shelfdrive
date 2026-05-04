<?php

namespace Tests\Feature\Bookmarks;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\EbookBookmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookmarkControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/bookmarks')->assertStatus(401);
        $this->getJson('/api/v1/library/1/bookmarks')->assertStatus(401);
        $this->postJson('/api/v1/library/1/bookmarks', [])->assertStatus(401);
        $this->deleteJson('/api/v1/bookmarks/1')->assertStatus(401);
    }

    public function test_store_creates_bookmark_with_inherited_format(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account, ['format' => DriveFile::FORMAT_EPUB]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/{$file->id}/bookmarks", ['cfi' => 'epubcfi(/6/4!/4)', 'label' => 'intro'])
            ->assertStatus(201)
            ->assertJsonPath('data.format', 'epub')
            ->assertJsonPath('data.label', 'intro');

        $this->assertSame(1, $user->ebookBookmarks()->count());
    }

    public function test_store_foreign_file_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);

        $this->actingAs($intruder)
            ->postJson("/api/v1/library/{$file->id}/bookmarks", ['page' => 5])
            ->assertStatus(404);

        $this->assertSame(0, EbookBookmark::count());
    }

    public function test_per_file_index_lists_bookmarks_in_page_order(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $user->ebookBookmarks()->create(['drive_file_id' => $file->id, 'format' => 'pdf', 'page' => 30]);
        $user->ebookBookmarks()->create(['drive_file_id' => $file->id, 'format' => 'pdf', 'page' => 10]);
        $user->ebookBookmarks()->create(['drive_file_id' => $file->id, 'format' => 'pdf', 'page' => 20]);

        $pages = collect(
            $this->actingAs($user)->getJson("/api/v1/library/{$file->id}/bookmarks")->json('data'),
        )->pluck('page')->all();

        $this->assertSame([10, 20, 30], $pages);
    }

    public function test_global_index_owner_scoped_with_drive_file(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aAccount = $this->driveAccount($alice);
        $bAccount = $this->driveAccount($bob);
        $aFile = $this->seedFile($alice, $aAccount, ['name' => 'A.pdf']);
        $bFile = $this->seedFile($bob, $bAccount, ['name' => 'B.pdf']);
        $alice->ebookBookmarks()->create(['drive_file_id' => $aFile->id, 'format' => 'pdf', 'page' => 1]);
        $bob->ebookBookmarks()->create(['drive_file_id' => $bFile->id, 'format' => 'pdf', 'page' => 1]);

        $response = $this->actingAs($alice)->getJson('/api/v1/bookmarks');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.drive_file.name', 'A.pdf');
    }

    public function test_destroy_owner_only(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);
        $bookmark = $owner->ebookBookmarks()->create(['drive_file_id' => $file->id, 'format' => 'pdf', 'page' => 1]);

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/bookmarks/{$bookmark->id}")
            ->assertStatus(404);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/bookmarks/{$bookmark->id}")
            ->assertNoContent();

        $this->assertSame(0, EbookBookmark::count());
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

    private function seedFile(User $user, ConnectedGoogleAccount $account, array $overrides = []): DriveFile
    {
        $df = new DriveFile(array_merge([
            'drive_file_id' => 'd-'.uniqid('', true),
            'name' => 'book.pdf',
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
