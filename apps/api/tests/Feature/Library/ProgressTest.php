<?php

namespace Tests\Feature\Library;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/library/1/progress')->assertStatus(401);
        $this->patchJson('/api/v1/library/1/progress', [])->assertStatus(401);
    }

    public function test_show_returns_null_when_no_progress(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->actingAs($user)
            ->getJson("/api/v1/library/{$file->id}/progress")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_first_update_creates_row_then_idempotent(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->actingAs($user)
            ->patchJson("/api/v1/library/{$file->id}/progress", ['page' => 5, 'percent' => 10.5])
            ->assertOk()
            ->assertJsonPath('data.page', 5)
            ->assertJsonPath('data.format', 'pdf');

        $this->assertSame(1, $user->readingProgress()->count());

        $this->actingAs($user)
            ->patchJson("/api/v1/library/{$file->id}/progress", ['page' => 12, 'percent' => 30])
            ->assertOk()
            ->assertJsonPath('data.page', 12);

        // Still one row — we update in place per (user, drive_file).
        $this->assertSame(1, $user->readingProgress()->count());
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $this->actingAs($user)
            ->patchJson("/api/v1/library/{$file->id}/progress", ['page' => -1])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patchJson("/api/v1/library/{$file->id}/progress", ['percent' => 200])
            ->assertStatus(422);
    }

    public function test_foreign_file_returns_404_on_show_and_update(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);

        $this->actingAs($intruder)
            ->getJson("/api/v1/library/{$file->id}/progress")
            ->assertStatus(404);

        $this->actingAs($intruder)
            ->patchJson("/api/v1/library/{$file->id}/progress", ['page' => 1])
            ->assertStatus(404);

        $this->assertSame(0, \App\Models\ReadingProgress::count());
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
