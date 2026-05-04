<?php

namespace Tests\Feature\Duplicates;

use App\Jobs\Drive\DetectDuplicates;
use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\DuplicateGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_md5_match_creates_exact_account_scoped_group(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        $a = $this->seedFile($user, $account, ['name' => 'Same A.pdf', 'md5_checksum' => 'abc123', 'size_bytes' => 100, 'mime_type' => 'application/pdf']);
        $b = $this->seedFile($user, $account, ['name' => 'Same B.pdf', 'md5_checksum' => 'abc123', 'size_bytes' => 100, 'mime_type' => 'application/pdf']);

        DetectDuplicates::dispatchSync($user->id);

        $group = DuplicateGroup::where('user_id', $user->id)->first();
        $this->assertNotNull($group);
        $this->assertSame(DuplicateGroup::STRATEGY_MD5, $group->match_strategy);
        $this->assertSame(DuplicateGroup::CONFIDENCE_EXACT, $group->confidence);
        $this->assertSame(DuplicateGroup::SCOPE_ACCOUNT, $group->scope);

        $memberIds = $group->members()->pluck('drive_file_id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $memberIds);
    }

    public function test_name_size_mime_match_creates_likely_group(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        $this->seedFile($user, $account, ['name' => 'Atlas.pdf', 'size_bytes' => 500, 'mime_type' => 'application/pdf']);
        $this->seedFile($user, $account, ['name' => 'atlas.pdf', 'size_bytes' => 500, 'mime_type' => 'application/pdf']);
        $this->seedFile($user, $account, ['name' => 'Different.pdf', 'size_bytes' => 999, 'mime_type' => 'application/pdf']);

        DetectDuplicates::dispatchSync($user->id);

        $group = DuplicateGroup::where('user_id', $user->id)->first();
        $this->assertSame(DuplicateGroup::STRATEGY_NAME_SIZE_MIME, $group->match_strategy);
        $this->assertSame(DuplicateGroup::CONFIDENCE_LIKELY, $group->confidence);
        $this->assertSame(2, $group->members()->count());
    }

    public function test_cross_account_scope_when_match_spans_accounts(): void
    {
        $user = User::factory()->create();
        $a1 = $this->driveAccount($user, 'sub-1', 'a@example.com');
        $a2 = $this->driveAccount($user, 'sub-2', 'b@example.com');

        $this->seedFile($user, $a1, ['name' => 'X.pdf', 'md5_checksum' => 'xyz', 'size_bytes' => 1, 'mime_type' => 'application/pdf']);
        $this->seedFile($user, $a2, ['name' => 'X.pdf', 'md5_checksum' => 'xyz', 'size_bytes' => 1, 'mime_type' => 'application/pdf']);

        DetectDuplicates::dispatchSync($user->id);

        $group = DuplicateGroup::where('user_id', $user->id)->first();
        $this->assertSame(DuplicateGroup::SCOPE_CROSS_ACCOUNT, $group->scope);
    }

    public function test_md5_takes_precedence_over_lower_confidence_strategies(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        // Two files share md5; both also have the same name+size+mime.
        $a = $this->seedFile($user, $account, ['name' => 'shared.pdf', 'size_bytes' => 50, 'mime_type' => 'application/pdf', 'md5_checksum' => 'm1']);
        $b = $this->seedFile($user, $account, ['name' => 'shared.pdf', 'size_bytes' => 50, 'mime_type' => 'application/pdf', 'md5_checksum' => 'm1']);
        // A third file shares ONLY the name with the others.
        $c = $this->seedFile($user, $account, ['name' => 'shared.pdf', 'size_bytes' => 999, 'mime_type' => 'application/pdf']);

        DetectDuplicates::dispatchSync($user->id);

        $groups = DuplicateGroup::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(1, $groups, 'a, b should land in the md5 group; c is alone after that pass');
        $this->assertSame(DuplicateGroup::STRATEGY_MD5, $groups[0]->match_strategy);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $groups[0]->members()->pluck('drive_file_id')->all(),
        );
    }

    public function test_resolved_groups_are_preserved_on_re_detection(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        $a = $this->seedFile($user, $account, ['md5_checksum' => 'm1']);
        $b = $this->seedFile($user, $account, ['md5_checksum' => 'm1']);

        DetectDuplicates::dispatchSync($user->id);
        $group = DuplicateGroup::where('user_id', $user->id)->first();
        $group->forceFill([
            'canonical_drive_file_id' => $a->id,
            'resolved_at' => now(),
        ])->save();

        DetectDuplicates::dispatchSync($user->id);

        $group->refresh();
        $this->assertNotNull($group->resolved_at, 'resolved group must survive a re-run');
        $this->assertSame(1, DuplicateGroup::where('user_id', $user->id)->count());
        $this->assertSame(2, $group->members()->count());
    }

    public function test_unresolved_groups_are_rebuilt_on_re_detection(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        $this->seedFile($user, $account, ['md5_checksum' => 'm1']);
        $this->seedFile($user, $account, ['md5_checksum' => 'm1']);

        DetectDuplicates::dispatchSync($user->id);
        $first = DuplicateGroup::where('user_id', $user->id)->first();

        DetectDuplicates::dispatchSync($user->id);
        $second = DuplicateGroup::where('user_id', $user->id)->first();

        $this->assertNotEquals($first->id, $second->id);
    }

    public function test_visible_only_files_participate(): void
    {
        $user = User::factory()->create();
        $account = $this->driveAccount($user);

        $this->seedFile($user, $account, ['md5_checksum' => 'k', 'trashed' => true]);
        $this->seedFile($user, $account, ['md5_checksum' => 'k']);

        DetectDuplicates::dispatchSync($user->id);

        $this->assertSame(0, DuplicateGroup::where('user_id', $user->id)->count());
    }

    public function test_normalize_name_collapses_whitespace_and_punctuation(): void
    {
        $a = DetectDuplicates::normalizeName('Cardiology - Atlas (Vol. 3).pdf');
        $b = DetectDuplicates::normalizeName('cardiology__atlas_vol_3.pdf');
        $this->assertSame($a, $b);
    }

    private function driveAccount(User $user, string $sub = 'sub', string $email = 'drive@example.com'): ConnectedGoogleAccount
    {
        return $user->connectedGoogleAccounts()->create([
            'google_sub' => $sub,
            'email' => $email,
            'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ]);
    }

    private function seedFile(User $user, ConnectedGoogleAccount $account, array $overrides): DriveFile
    {
        $df = new DriveFile(array_merge([
            'drive_file_id' => 'd-'.uniqid('', true),
            'name' => 'Untitled.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'format' => DriveFile::FORMAT_PDF,
            'trashed' => false,
        ], $overrides));
        $df->user_id = $user->id;
        $df->connected_account_id = $account->id;
        $df->save();

        return $df;
    }
}
