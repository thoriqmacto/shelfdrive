<?php

namespace Tests\Support;

use App\Models\ConnectedGoogleAccount;
use App\Services\Google\DriveClient;
use App\Services\Google\OAuthService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

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

    /**
     * Set on the test side via $fake->mediaResponse = ['body' => '...'];
     * Captured headers inspected via $fake->lastRangeHeader.
     *
     * @var array{body?:string, status?:int, headers?:array<string,string>}|null
     */
    public ?array $mediaResponse = null;

    public ?string $lastRangeHeader = null;

    public ?string $lastFileId = null;

    public function getFileMedia(
        ConnectedGoogleAccount $account,
        string $fileId,
        ?string $rangeHeader = null,
    ): Response {
        $this->lastRangeHeader = $rangeHeader;
        $this->lastFileId = $fileId;

        $body = $this->mediaResponse['body'] ?? '%PDF-1.4 fake bytes';
        $status = $this->mediaResponse['status'] ?? ($rangeHeader ? 206 : 200);
        $headers = array_merge(
            [
                'Content-Type' => 'application/pdf',
                'Content-Length' => (string) strlen($body),
            ],
            $rangeHeader ? ['Content-Range' => 'bytes 0-'.(strlen($body) - 1).'/'.strlen($body)] : [],
            $this->mediaResponse['headers'] ?? [],
        );

        return new Response(new Psr7Response($status, $headers, $body));
    }
}
