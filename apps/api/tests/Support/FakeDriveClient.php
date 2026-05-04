<?php

namespace Tests\Support;

use App\Models\ConnectedGoogleAccount;
use App\Services\Google\DriveClient;
use App\Services\Google\OAuthService;

/**
 * Test double for App\Services\Google\DriveClient. Bind via the app
 * container to inject deterministic file pages into ScanDriveAccount
 * (and any other consumer).
 *
 * Usage:
 *   $this->app->instance(
 *       DriveClient::class,
 *       new FakeDriveClient([
 *           ['files' => [...], 'nextPageToken' => 'p2'],
 *           ['files' => [...]],
 *       ]),
 *   );
 */
class FakeDriveClient extends DriveClient
{
    /** @var list<array<string,mixed>> */
    private array $pages;

    private int $cursor = 0;

    /** @param  list<array<string,mixed>>  $pages */
    public function __construct(array $pages)
    {
        // Skip parent constructor — the OAuthService dep is unused on the
        // fake path, and we don't want PHPUnit to resolve the real one.
        $this->pages = $pages;
    }

    public function listFiles(ConnectedGoogleAccount $account, array $params): array
    {
        $page = $this->pages[$this->cursor] ?? ['files' => []];
        $this->cursor++;

        return $page;
    }
}
