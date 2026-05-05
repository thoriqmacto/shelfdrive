<?php

namespace Tests\Feature\Library;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/library/1')->assertStatus(401);
    }

    public function test_owner_sees_metadata_progress_and_counts(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account, ['name' => 'Cardio.pdf']);

        $user->readingProgress()->create([
            'drive_file_id' => $file->id,
            'format' => $file->format,
            'page' => 42,
            'percent' => 12.5,
            'last_read_at' => now(),
        ]);
        $user->ebookBookmarks()->create([
            'drive_file_id' => $file->id,
            'format' => $file->format,
            'page' => 10,
        ]);
        $user->ebookBookmarks()->create([
            'drive_file_id' => $file->id,
            'format' => $file->format,
            'page' => 20,
        ]);
        $user->ebookNotes()->create([
            'drive_file_id' => $file->id,
            'format' => $file->format,
            'page' => 5,
            'body' => 'note',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/library/{$file->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.name', 'Cardio.pdf')
            ->assertJsonPath('data.progress.page', 42)
            ->assertJsonPath('data.bookmark_count', 2)
            ->assertJsonPath('data.note_count', 1);
    }

    public function test_owner_sees_null_progress_when_unread(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->actingAs($user)
            ->getJson("/api/v1/library/{$file->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', null)
            ->assertJsonPath('data.bookmark_count', 0)
            ->assertJsonPath('data.note_count', 0);
    }

    public function test_foreign_returns_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);

        $this->actingAs($intruder)
            ->getJson("/api/v1/library/{$file->id}")
            ->assertStatus(404);
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
