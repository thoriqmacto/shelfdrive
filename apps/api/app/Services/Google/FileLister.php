<?php

namespace App\Services\Google;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use Generator;

/**
 * Pages through Drive `files.list` for ebook files. Yields one file
 * metadata array at a time so scan jobs can stream-process huge
 * libraries without loading every page into memory at once.
 */
class FileLister
{
    public function __construct(private DriveClient $client)
    {
    }

    /**
     * @return Generator<int, array<string,mixed>>
     */
    public function ebooks(ConnectedGoogleAccount $account): Generator
    {
        $pageToken = null;

        do {
            $params = ['q' => DriveFile::ebookSearchQuery()];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $page = $this->client->listFiles($account, $params);

            foreach ($page['files'] ?? [] as $file) {
                yield $file;
            }

            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);
    }
}
