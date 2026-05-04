<?php

namespace App\Jobs\Drive;

use App\Models\DriveFile;
use App\Models\DuplicateGroup;
use App\Models\DuplicateGroupMember;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rebuilds the user's unresolved duplicate groups across all of their
 * connected Drive accounts. Resolved groups (with `resolved_at` set)
 * are preserved so the user's choices stick.
 *
 * Three strategies, ranked by confidence:
 *   - md5: same `md5_checksum` (exact)
 *   - name_size_mime: same normalized name + size_bytes + mime_type (likely)
 *   - name_only: same normalized name (possible)
 *
 * Each cluster is tagged `account` (all members share one connected
 * account) or `cross_account` (members span multiple) — the UI uses
 * the tag to order presentation per the user's preference: intra-
 * account dupes first, cross-account next.
 *
 * The match_strategy passes are layered: a file already locked into a
 * higher-confidence group is excluded from the lower-confidence passes
 * so the same file doesn't appear in three different groups.
 */
class DetectDuplicates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $userId)
    {
    }

    public function uniqueId(): string
    {
        return "detect-duplicates:{$this->userId}";
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $files = $user->driveFiles()->visible()->get([
            'id',
            'connected_account_id',
            'name',
            'mime_type',
            'size_bytes',
            'md5_checksum',
        ]);

        DB::transaction(function () use ($user, $files) {
            // Wipe unresolved groups for this user so re-detection is
            // idempotent. Resolved rows (with the user's keeper choice)
            // stay forever.
            $unresolvedIds = DuplicateGroup::where('user_id', $user->id)
                ->unresolved()
                ->pluck('id');

            DuplicateGroupMember::whereIn('duplicate_group_id', $unresolvedIds)->delete();
            DuplicateGroup::whereIn('id', $unresolvedIds)->delete();

            // Files already locked into a resolved group: skip those —
            // the user has already decided how to handle them.
            $resolvedFileIds = DuplicateGroupMember::query()
                ->whereIn('duplicate_group_id',
                    DuplicateGroup::where('user_id', $user->id)
                        ->whereNotNull('resolved_at')
                        ->pluck('id'),
                )
                ->pluck('drive_file_id')
                ->all();

            $taken = array_fill_keys($resolvedFileIds, true);
            $createdGroups = 0;

            foreach (
                [
                    DuplicateGroup::STRATEGY_MD5,
                    DuplicateGroup::STRATEGY_NAME_SIZE_MIME,
                    DuplicateGroup::STRATEGY_NAME_ONLY,
                ] as $strategy
            ) {
                $clusters = $this->cluster($files, $strategy, $taken);
                foreach ($clusters as $cluster) {
                    if (count($cluster) < 2) continue;

                    $accountIds = array_unique(array_map(fn ($f) => $f->connected_account_id, $cluster));
                    $scope = count($accountIds) === 1
                        ? DuplicateGroup::SCOPE_ACCOUNT
                        : DuplicateGroup::SCOPE_CROSS_ACCOUNT;

                    $group = $user->duplicateGroups()->create([
                        'match_strategy' => $strategy,
                        'confidence' => DuplicateGroup::confidenceFor($strategy),
                        'scope' => $scope,
                    ]);

                    foreach ($cluster as $f) {
                        $group->members()->create(['drive_file_id' => $f->id]);
                        $taken[$f->id] = true;
                    }
                    $createdGroups++;
                }
            }

            Log::info('DetectDuplicates: rebuilt groups for user', [
                'user_id' => $user->id,
                'groups' => $createdGroups,
            ]);
        });
    }

    /**
     * @param  iterable<DriveFile>  $files
     * @param  array<int,bool>  $taken  drive_file ids already claimed
     * @return list<list<DriveFile>>
     */
    private function cluster(iterable $files, string $strategy, array $taken): array
    {
        $buckets = [];
        foreach ($files as $f) {
            if (isset($taken[$f->id])) continue;
            $key = $this->signatureFor($f, $strategy);
            if ($key === null) continue;
            $buckets[$key][] = $f;
        }

        return array_values(array_filter($buckets, fn ($b) => count($b) >= 2));
    }

    private function signatureFor(DriveFile $f, string $strategy): ?string
    {
        return match ($strategy) {
            DuplicateGroup::STRATEGY_MD5 => $f->md5_checksum ? "md5:{$f->md5_checksum}" : null,
            DuplicateGroup::STRATEGY_NAME_SIZE_MIME => $f->size_bytes !== null && $f->mime_type
                ? 'nsm:'.self::normalizeName($f->name).':'.$f->size_bytes.':'.$f->mime_type
                : null,
            DuplicateGroup::STRATEGY_NAME_ONLY => 'n:'.self::normalizeName($f->name),
            default => null,
        };
    }

    /**
     * Strip extension, lowercase, NFC, collapse whitespace + punctuation
     * so files differing only in surface formatting cluster together.
     */
    public static function normalizeName(string $name): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        if (function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($stem, \Normalizer::FORM_C);
            if ($normalized !== false && $normalized !== null) {
                $stem = $normalized;
            }
        }
        $stem = mb_strtolower($stem);
        $stem = preg_replace('/[\s\-_.()\[\]{}]+/u', ' ', $stem) ?? $stem;

        return trim($stem);
    }
}
