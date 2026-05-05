<?php

namespace Tests\Feature\Notes;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\EbookNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbookNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/notes')->assertStatus(401);
        $this->getJson('/api/v1/library/1/notes')->assertStatus(401);
        $this->postJson('/api/v1/library/1/notes', [])->assertStatus(401);
        $this->patchJson('/api/v1/notes/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/notes/1')->assertStatus(401);
    }

    public function test_store_and_update_round_trip(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $file = $this->seedFile($user, $account);

        $created = $this->actingAs($user)
            ->postJson("/api/v1/library/{$file->id}/notes", [
                'page' => 7,
                'selection_text' => 'foo',
                'body' => 'first thoughts',
                'color' => 'yellow',
            ])
            ->assertStatus(201)
            ->json('data');

        $this->actingAs($user)
            ->patchJson("/api/v1/notes/{$created['id']}", ['body' => 'revised'])
            ->assertOk()
            ->assertJsonPath('data.body', 'revised');

        $this->assertSame('revised', EbookNote::find($created['id'])->body);
    }

    public function test_global_index_owner_scoped(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aAccount = $this->driveAccount($alice);
        $bAccount = $this->driveAccount($bob);
        $aFile = $this->seedFile($alice, $aAccount);
        $bFile = $this->seedFile($bob, $bAccount);

        $alice->ebookNotes()->create(['drive_file_id' => $aFile->id, 'format' => 'pdf', 'body' => 'a']);
        $bob->ebookNotes()->create(['drive_file_id' => $bFile->id, 'format' => 'pdf', 'body' => 'b']);

        $this->actingAs($alice)
            ->getJson('/api/v1/notes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'a');
    }

    public function test_per_file_index_returns_only_that_files_notes(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);
        $a = $this->seedFile($user, $account);
        $b = $this->seedFile($user, $account);

        $user->ebookNotes()->create(['drive_file_id' => $a->id, 'format' => 'pdf', 'body' => 'aa', 'page' => 2]);
        $user->ebookNotes()->create(['drive_file_id' => $b->id, 'format' => 'pdf', 'body' => 'bb', 'page' => 9]);

        $this->actingAs($user)
            ->getJson("/api/v1/library/{$a->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'aa');
    }

    public function test_foreign_file_returns_404_on_store(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);

        $this->actingAs($intruder)
            ->postJson("/api/v1/library/{$file->id}/notes", ['body' => 'pwn'])
            ->assertStatus(404);

        $this->assertSame(0, EbookNote::count());
    }

    public function test_destroy_owner_only(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $account = $this->driveAccount($owner);
        $file = $this->seedFile($owner, $account);
        $note = $owner->ebookNotes()->create(['drive_file_id' => $file->id, 'format' => 'pdf', 'body' => 'x']);

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/notes/{$note->id}")
            ->assertStatus(404);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/notes/{$note->id}")
            ->assertNoContent();

        $this->assertSame(0, EbookNote::count());
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
