<?php

namespace App\Services\Google;

use App\Models\ConnectedGoogleAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin Drive REST client bound to a single ConnectedGoogleAccount.
 *
 * Lives over Laravel's HTTP client (Phase 4 stays SDK-free; the
 * google/apiclient SDK lands in Phase 8 with chunked resumable upload).
 *
 * Auto-refreshes the account's access token when it's near expiry, and
 * marks the account `revoked` if Google returns invalid_grant.
 *
 * Tests should bind a fake of this class via the container; see
 * tests/Feature/Library/* for the pattern.
 */
class DriveClient
{
    public const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    private const REFRESH_LEEWAY_SECONDS = 60;

    public function __construct(private OAuthService $oauth)
    {
    }

    /**
     * One page of `files.list` with the requested fields.
     *
     * @param  array<string,mixed>  $params  Caller-supplied query overrides
     * @return array{files: list<array<string,mixed>>, nextPageToken?: string}
     */
    public function listFiles(ConnectedGoogleAccount $account, array $params): array
    {
        $defaults = [
            'fields' => 'nextPageToken,files(id,name,mimeType,size,md5Checksum,parents,modifiedTime,webViewLink,thumbnailLink,trashed)',
            'pageSize' => 1000,
            'spaces' => 'drive',
            'corpora' => 'user',
            'supportsAllDrives' => 'false',
        ];

        return $this->authedGet($account, self::FILES_URL, array_merge($defaults, $params))->json();
    }

    /**
     * `files.get?alt=media` with optional Range support — used by the
     * streaming proxy in Phase 7. Returns the underlying Response so
     * the caller can stream the body and forward Content-Range / etc.
     */
    public function getFileMedia(
        ConnectedGoogleAccount $account,
        string $fileId,
        ?string $rangeHeader = null,
    ): Response {
        $headers = ['Authorization' => 'Bearer '.$this->ensureFreshAccessToken($account)];
        if ($rangeHeader) {
            $headers['Range'] = $rangeHeader;
        }

        return Http::withHeaders($headers)
            ->withOptions(['stream' => true])
            ->get(self::FILES_URL.'/'.urlencode($fileId), ['alt' => 'media']);
    }

    /**
     * Authed GET helper. Single-retry on 401: refresh the token, try
     * again. After that we surface the failure to the caller.
     */
    private function authedGet(ConnectedGoogleAccount $account, string $url, array $params): Response
    {
        $token = $this->ensureFreshAccessToken($account);

        $response = Http::withToken($token)->acceptJson()->get($url, $params);

        if ($response->status() === 401) {
            $token = $this->forceRefresh($account);
            $response = Http::withToken($token)->acceptJson()->get($url, $params);
        }

        $response->throw();

        return $response;
    }

    /**
     * Ensure the row's access_token has at least REFRESH_LEEWAY_SECONDS
     * remaining; refresh + persist if not. Returns the live access token
     * (never persisted in plaintext anywhere outside the encrypted cast).
     */
    private function ensureFreshAccessToken(ConnectedGoogleAccount $account): string
    {
        $expiresAt = $account->token_expires_at;
        if (
            $account->access_token
            && $expiresAt
            && $expiresAt->isFuture()
            && $expiresAt->diffInSeconds(now()) > self::REFRESH_LEEWAY_SECONDS
        ) {
            return $account->access_token;
        }

        return $this->forceRefresh($account);
    }

    private function forceRefresh(ConnectedGoogleAccount $account): string
    {
        if (! $account->refresh_token) {
            $account->update(['status' => ConnectedGoogleAccount::STATUS_REVOKED]);
            throw new \RuntimeException("ConnectedGoogleAccount #{$account->id} has no refresh token; cannot refresh access.");
        }

        try {
            $tokens = $this->oauth->refreshAccessToken('drive', $account->refresh_token);
        } catch (RequestException $e) {
            // invalid_grant or revoked → mark and bubble up; caller handles UI.
            $body = (string) $e->response->body();
            if (str_contains($body, 'invalid_grant')) {
                $account->update(['status' => ConnectedGoogleAccount::STATUS_REVOKED]);
            } else {
                $account->update(['status' => ConnectedGoogleAccount::STATUS_ERROR]);
            }
            throw $e;
        }

        $account->forceFill([
            'access_token' => $tokens['access_token'],
            'token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : null,
            'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
        ])->save();

        return $tokens['access_token'];
    }
}
