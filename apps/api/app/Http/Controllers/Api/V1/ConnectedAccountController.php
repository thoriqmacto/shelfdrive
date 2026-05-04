<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedGoogleAccount;
use App\Services\Google\OAuthService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manages additional Google accounts a user has connected for Drive
 * scanning. Distinct from the primary login account: a user has exactly
 * one `purpose=login` row, and zero or more `purpose=drive` rows.
 *
 * The OAuth start endpoint is authenticated (the user must already be
 * signed in to bind a new Drive). The callback is public because Google
 * redirects the bare browser back to it — the binding is recovered from
 * a server-side cache entry keyed by the OAuth state token.
 */
class ConnectedAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->connectedGoogleAccounts()
            ->orderByDesc('id')
            ->get(['id', 'google_sub', 'email', 'display_name', 'purpose', 'status', 'last_full_scan_at', 'last_incremental_sync_at', 'created_at']);

        return response()->json(['data' => $accounts->map(fn (ConnectedGoogleAccount $a) => $this->present($a))]);
    }

    /**
     * Stage 1 of the drive-connect flow.
     *
     * Authenticated. Returns a JSON `{ url }` the web client opens (rather
     * than a 302) so the redirect happens via `window.location.href` from
     * inside the SPA. This avoids losing the browser's localStorage-backed
     * Sanctum session that an XHR-followed redirect would otherwise hide.
     */
    public function connectStart(Request $request, OAuthService $oauth): JsonResponse
    {
        $next = $this->safeNextPath($request->query('next'));
        $state = Str::random(40);

        Cache::put(
            $this->stateCacheKey($state),
            [
                'user_id' => $request->user()->id,
                'next' => $next,
            ],
            now()->addMinutes(10),
        );

        return response()->json([
            'url' => $oauth->buildAuthUrl('drive', $state),
        ]);
    }

    /**
     * Stage 2 of the drive-connect flow.
     *
     * Public route — Google redirects the browser back here. The originating
     * user is recovered from the server-side state cache (never the query
     * string). The user's existing Sanctum session in localStorage is
     * preserved across the redirect, so we just bounce them back to
     * /accounts on the web app with a status flag.
     */
    public function connectCallback(Request $request, OAuthService $oauth): RedirectResponse
    {
        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = $request->query('error');

        if ($error !== null) {
            return redirect("{$frontend}/accounts?drive_error=".urlencode((string) $error));
        }

        $payload = $state === '' ? null : Cache::pull($this->stateCacheKey($state));

        if (! is_array($payload) || empty($payload['user_id']) || $code === '') {
            return redirect("{$frontend}/accounts?drive_error=invalid_state");
        }

        $user = \App\Models\User::find($payload['user_id']);
        if (! $user) {
            return redirect("{$frontend}/accounts?drive_error=invalid_state");
        }

        try {
            $tokens = $oauth->exchangeCode('drive', $code);
            $userInfo = $oauth->fetchUserInfo($tokens['access_token']);
        } catch (RequestException $e) {
            Log::warning('Drive connect exchange failed', ['status' => $e->response->status()]);

            return redirect("{$frontend}/accounts?drive_error=exchange_failed");
        }

        if (empty($userInfo['sub'])) {
            return redirect("{$frontend}/accounts?drive_error=invalid_userinfo");
        }

        // Refuse to attach the primary login account as a drive source —
        // tokens with login scopes can't read Drive anyway, and it'd be
        // confusing UX. The primary account is already in the model under
        // purpose=login.
        $loginSub = $user->google_sub;
        if ($loginSub !== null && $loginSub === $userInfo['sub']) {
            return redirect("{$frontend}/accounts?drive_error=primary_account_cannot_be_drive");
        }

        $user->connectedGoogleAccounts()->updateOrCreate(
            [
                'google_sub' => (string) $userInfo['sub'],
                'purpose' => ConnectedGoogleAccount::PURPOSE_DRIVE,
            ],
            [
                'email' => $userInfo['email'] ?? null,
                'display_name' => $userInfo['name'] ?? null,
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'scopes' => isset($tokens['scope']) ? explode(' ', $tokens['scope']) : null,
                'status' => ConnectedGoogleAccount::STATUS_ACTIVE,
            ],
        );

        $next = (string) ($payload['next'] ?? '/accounts');

        return redirect("{$frontend}{$next}?drive_connected=1&email=".urlencode((string) ($userInfo['email'] ?? '')));
    }

    /**
     * Disconnect a connected drive account (no Drive deletion happens —
     * just removes the binding and the encrypted tokens).
     *
     * Owner-only. Returns 404 (not 403) for foreign rows so existence is
     * indistinguishable from absence.
     */
    public function destroy(Request $request, ConnectedGoogleAccount $account): JsonResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);
        abort_if($account->purpose === ConnectedGoogleAccount::PURPOSE_LOGIN, 422, 'Cannot disconnect the primary login account.');

        $account->delete();

        return response()->json(null, 204);
    }

    private function present(ConnectedGoogleAccount $a): array
    {
        return [
            'id' => $a->id,
            'google_sub' => $a->google_sub,
            'email' => $a->email,
            'display_name' => $a->display_name,
            'purpose' => $a->purpose,
            'status' => $a->status,
            'last_full_scan_at' => $a->last_full_scan_at?->toIso8601String(),
            'last_incremental_sync_at' => $a->last_incremental_sync_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function stateCacheKey(string $state): string
    {
        return "google_oauth:drive:state:{$state}";
    }

    private function safeNextPath(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '/accounts';
        }
        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return '/accounts';
        }

        return $value;
    }
}
