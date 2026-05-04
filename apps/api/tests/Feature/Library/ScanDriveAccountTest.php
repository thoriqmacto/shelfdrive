<?php

namespace Tests\Feature\Library;

use App\Jobs\Drive\ScanDriveAccount;
use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Google\DriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDriveClient;
use Tests\TestCase;

class ScanDriveAccountTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeWithPages(array $pages): void
    {
        $this->app->instance(DriveClient::class, new FakeDriveClient($pages));
    }

    private function makeDriveAccount(?User $user = null): ConnectedGoogleAccount
    {
        $user ??= User::factory()->create();

        return $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub-'.$user->id,
            'email' => 'drive@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_indexes_pdf_epub_chm_djvu_paginated(): void
    {
        $this->bindFakeWithPages([
            [
                'nextPageToken' => 'p2',
                'files' => [
                    ['id' => 'd1', 'name' => 'Cardiology.pdf', 'mimeType' => 'application/pdf', 'size' => 1024, 'md5Checksum' => 'abc', 'parents' => ['fld1'], 'modifiedTime' => '2026-04-01T10:00:00Z', 'webViewLink' => 'https://drive.example/d1'],
                    ['id' => 'd2', 'name' => 'War and Peace.epub', 'mimeType' => 'application/epub+zip', 'size' => 2048, 'parents' => ['fld1'], 'modifiedTime' => '2026-04-02T10:00:00Z'],
                ],
            ],
            [
                'files' => [
                    ['id' => 'd3', 'name' => 'manual.chm', 'mimeType' => 'application/vnd.ms-htmlhelp', 'size' => 500, 'parents' => ['fld2']],
                    ['id' => 'd4', 'name' => 'scan.djvu', 'mimeType' => 'image/vnd.djvu', 'size' => 1500],
                    ['id' => 'd5', 'name' => 'mystery.bin', 'mimeType' => 'application/octet-stream', 'size' => 100],
                ],
            ],
        ]);

        $account = $this->makeDriveAccount();

        ScanDriveAccount::dispatchSync($account->id);

        $files = $account->driveFiles()->orderBy('drive_file_id')->get();
        $this->assertCount(5, $files);

        $byId = $files->keyBy('drive_file_id');
        $this->assertSame(DriveFile::FORMAT_PDF, $byId['d1']->format);
        $this->assertSame(DriveFile::FORMAT_EPUB, $byId['d2']->format);
        $this->assertSame(DriveFile::FORMAT_CHM, $byId['d3']->format);
        $this->assertSame(DriveFile::FORMAT_DJVU, $byId['d4']->format);
        $this->assertSame(DriveFile::FORMAT_OTHER, $byId['d5']->format);

        $this->assertSame($account->user_id, $byId['d1']->user_id);
        $this->assertSame(1024, $byId['d1']->size_bytes);
        $this->assertSame('abc', $byId['d1']->md5_checksum);
        $this->assertSame('fld1', $byId['d1']->parent_folder_id);
    }

    public function test_records_sync_run_with_counts(): void
    {
        $this->bindFakeWithPages([
            ['files' => [
                ['id' => 'a', 'name' => 'a.pdf', 'mimeType' => 'application/pdf'],
                ['id' => 'b', 'name' => 'b.pdf', 'mimeType' => 'application/pdf'],
            ]],
        ]);

        $account = $this->makeDriveAccount();
        ScanDriveAccount::dispatchSync($account->id, SyncRun::KIND_MANUAL);

        $run = $account->syncRuns()->latest('id')->first();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(SyncRun::KIND_MANUAL, $run->kind);
        $this->assertSame(2, $run->files_seen);
        $this->assertSame(2, $run->files_added);
        $this->assertSame(0, $run->files_updated);
        $this->assertSame(0, $run->files_removed);
        $this->assertNotNull($run->finished_at);

        $account->refresh();
        $this->assertNotNull($account->last_full_scan_at);
    }

    public function test_re_scan_marks_missing_files_trashed(): void
    {
        $account = $this->makeDriveAccount();

        $this->bindFakeWithPages([
            ['files' => [
                ['id' => 'a', 'name' => 'a.pdf', 'mimeType' => 'application/pdf'],
                ['id' => 'b', 'name' => 'b.pdf', 'mimeType' => 'application/pdf'],
            ]],
        ]);
        ScanDriveAccount::dispatchSync($account->id);

        $this->bindFakeWithPages([
            ['files' => [
                ['id' => 'a', 'name' => 'a.pdf', 'mimeType' => 'application/pdf'],
            ]],
        ]);
        ScanDriveAccount::dispatchSync($account->id);

        $a = $account->driveFiles()->where('drive_file_id', 'a')->first();
        $b = $account->driveFiles()->where('drive_file_id', 'b')->first();
        $this->assertFalse((bool) $a->trashed);
        $this->assertTrue((bool) $b->trashed);

        $latest = $account->syncRuns()->latest('id')->first();
        $this->assertSame(1, $latest->files_seen);
        $this->assertSame(0, $latest->files_added);
        $this->assertSame(1, $latest->files_removed);
    }

    public function test_skips_revoked_accounts(): void
    {
        $this->bindFakeWithPages([['files' => []]]);
        $user = User::factory()->create();
        $account = $user->connectedGoogleAccounts()->create([
            'google_sub' => 'sub',
            'email' => 'a@example.com',
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_REVOKED,
        ]);

        ScanDriveAccount::dispatchSync($account->id);

        $this->assertSame(0, $account->syncRuns()->count());
        $this->assertSame(0, $account->driveFiles()->count());
    }
}
