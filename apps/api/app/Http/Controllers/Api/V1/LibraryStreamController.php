<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DriveFile;
use App\Services\Google\DriveClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Range-aware proxy that streams ebook bytes from Google Drive to the
 * browser without persisting them locally. The browser's Range
 * requests (e.g. pdf.js's per-page byte windows) are forwarded
 * verbatim; we relay back Content-Length / Content-Range / Accept-
 * Ranges / Content-Type so range-streaming clients work end-to-end.
 *
 * OAuth tokens never leave this server: the browser carries a Sanctum
 * bearer to /api/v1/library/{file}/stream; this controller resolves
 * the owning ConnectedGoogleAccount, refreshes the Drive access token
 * if needed, then forwards bytes upstream.
 *
 * The endpoint accepts a one-off ?token=... query token (issued by the
 * /access endpoint) instead of an Authorization header so that
 * <iframe>/<embed>/<a target=_blank> tags — which can't set headers —
 * can stream a file safely. The query token is short-lived (60s),
 * single-use, and binds (user_id, drive_file_id).
 */
class LibraryStreamController extends Controller
{
    private const ACCESS_TTL_SECONDS = 60;
    private const ACCESS_CACHE_PREFIX = 'library_stream:access:';

    public function __construct(private DriveClient $client)
    {
    }

    /**
     * Mint a single-use stream-access token bound to (user, file).
     * The viewer fetches this just before mounting the PDF so the
     * <embed>/pdf.js can hit /stream?token=… without an Authorization
     * header. Owner-scoped; foreign 404.
     */
    public function access(Request $request, DriveFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $token = bin2hex(random_bytes(24));
        \Illuminate\Support\Facades\Cache::put(
            self::ACCESS_CACHE_PREFIX.$token,
            ['user_id' => $request->user()->id, 'drive_file_id' => $file->id],
            now()->addSeconds(self::ACCESS_TTL_SECONDS),
        );

        return response()->json([
            'token' => $token,
            'expires_in' => self::ACCESS_TTL_SECONDS,
        ]);
    }

    /**
     * Stream the file. Authenticates either via Sanctum (when called
     * from XHR with the bearer header) or via ?token= consumed once
     * from cache (for <embed>/<a target=_blank>). Owner-scoped.
     */
    public function stream(Request $request, DriveFile $file): StreamedResponse
    {
        $authedUserId = $this->resolveAuthedUserId($request, $file);
        abort_if($authedUserId === null || $authedUserId !== $file->user_id, 404);

        $account = $file->connectedAccount;
        abort_if($account === null, 404);

        try {
            $upstream = $this->client->getFileMedia(
                $account,
                $file->drive_file_id,
                $request->header('Range'),
            );
        } catch (RequestException $e) {
            abort($e->response->status() === 404 ? 404 : 502, 'Could not fetch the file from Drive.');
        }

        $status = $upstream->status();
        $body = $upstream->toPsrResponse()->getBody();

        $forwardedHeaders = [
            'Content-Type' => $upstream->header('Content-Type') ?: $file->mime_type ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
        ];
        if ($len = $upstream->header('Content-Length')) {
            $forwardedHeaders['Content-Length'] = $len;
        }
        if ($range = $upstream->header('Content-Range')) {
            $forwardedHeaders['Content-Range'] = $range;
        }

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                if (function_exists('flush')) flush();
            }
        }, $status, $forwardedHeaders);
    }

    /**
     * Resolve the authenticated user id from either the Sanctum guard
     * or a ?token= query parameter (single-use). Returns null on
     * neither.
     */
    private function resolveAuthedUserId(Request $request, DriveFile $file): ?int
    {
        $sanctumUser = $request->user('sanctum');
        if ($sanctumUser !== null) {
            return $sanctumUser->id;
        }

        $token = (string) $request->query('token', '');
        if ($token === '') {
            return null;
        }

        $key = self::ACCESS_CACHE_PREFIX.$token;
        $payload = \Illuminate\Support\Facades\Cache::pull($key);
        if (! is_array($payload)) {
            return null;
        }
        if ((int) ($payload['drive_file_id'] ?? 0) !== $file->id) {
            return null;
        }

        return (int) ($payload['user_id'] ?? 0) ?: null;
    }
}
