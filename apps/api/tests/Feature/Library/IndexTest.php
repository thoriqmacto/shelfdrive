<?php

namespace Tests\Feature\Library;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/library')->assertStatus(401);
    }

    public function test_lists_only_own_files_with_pagination_meta(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->seedDriveFile($alice, ['name' => 'Alice book.pdf']);
        $this->seedDriveFile($alice, ['name' => 'Alice 2.epub']);
        $this->seedDriveFile($bob, ['name' => 'Bob book.pdf']);

        $response = $this->actingAs($alice)->getJson('/api/v1/library');
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('Bob book.pdf', $names);
    }

    public function test_search_filter_matches_name(): void
    {
        $user = User::factory()->create();
        $this->seedDriveFile($user, ['name' => 'Cardiology Atlas.pdf']);
        $this->seedDriveFile($user, ['name' => 'Romance.epub']);

        $this->actingAs($user)
            ->getJson('/api/v1/library?q=cardio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cardiology Atlas.pdf');
    }

    public function test_format_filter(): void
    {
        $user = User::factory()->create();
        $this->seedDriveFile($user, ['name' => 'a.pdf', 'format' => DriveFile::FORMAT_PDF]);
        $this->seedDriveFile($user, ['name' => 'b.epub', 'format' => DriveFile::FORMAT_EPUB]);

        $this->actingAs($user)
            ->getJson('/api/v1/library?format=epub')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.format', 'epub');
    }

    public function test_excludes_trashed_and_removed_from_library(): void
    {
        $user = User::factory()->create();
        $this->seedDriveFile($user, ['name' => 'visible.pdf']);
        $this->seedDriveFile($user, ['name' => 'trashed.pdf', 'trashed' => true]);
        $this->seedDriveFile($user, ['name' => 'removed.pdf', 'removed_from_library_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/v1/library')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'visible.pdf');
    }

    private function seedDriveFile(User $user, array $overrides = []): DriveFile
    {
        $account = $user->connectedGoogleAccounts()->first()
            ?? $user->connectedGoogleAccounts()->create([
                'google_sub' => 'sub-'.$user->id,
                'email' => 'drive@example.com',
                'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
                'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
            ]);

        $base = [
            'drive_file_id' => 'd-'.uniqid(),
            'mime_type' => 'application/pdf',
            'format' => DriveFile::FORMAT_PDF,
            'trashed' => false,
            'user_id' => $user->id,
            'connected_account_id' => $account->id,
        ];

        $df = new DriveFile(array_merge($base, $overrides));
        $df->user_id = $user->id;
        $df->connected_account_id = $account->id;
        $df->drive_file_id = $base['drive_file_id'];
        $df->save();

        return $df;
    }
}
