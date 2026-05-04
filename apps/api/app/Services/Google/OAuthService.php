<?php

namespace App\Services\Google;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Generic Google OAuth 2.0 helper used by both the login flow and the
 * drive-connect flow. The two flows differ only in the configured client
 * (`services.google.login` vs `services.google.drive`).
 *
 * Network calls go through Laravel's HTTP client so tests can use
 * `Http::fake(...)` without any extra plumbing.
 */
class OAuthService
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Build the Google consent screen URL. `prompt=consent` + `access_type=offline`
     * guarantees we get a refresh token on every grant.
     */
    public function buildAuthUrl(string $purpose, string $state, ?string $loginHint = null): string
    {
        $cfg = $this->config($purpose);

        $params = [
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $cfg['scopes']),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ];

        if ($loginHint !== null && $loginHint !== '') {
            $params['login_hint'] = $loginHint;
        }

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /**
     * Exchange an authorization code for an access + refresh token pair.
     *
     * @return array{access_token:string, refresh_token?:string, expires_in:int, scope:string, token_type:string, id_token?:string}
     *
     * @throws RequestException
     */
    public function exchangeCode(string $purpose, string $code): array
    {
        $cfg = $this->config($purpose);

        $resp = Http::asForm()
            ->acceptJson()
            ->post(self::TOKEN_URL, [
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'code' => $code,
                'redirect_uri' => $cfg['redirect_uri'],
                'grant_type' => 'authorization_code',
            ])
            ->throw();

        return $resp->json();
    }

    /**
     * Refresh an access token using a stored refresh token.
     *
     * @return array{access_token:string, expires_in:int, scope:string, token_type:string}
     *
     * @throws RequestException
     */
    public function refreshAccessToken(string $purpose, string $refreshToken): array
    {
        $cfg = $this->config($purpose);

        $resp = Http::asForm()
            ->acceptJson()
            ->post(self::TOKEN_URL, [
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
            ->throw();

        return $resp->json();
    }

    /**
     * Fetch the OpenID Connect userinfo for an access token.
     *
     * @return array{sub:string, email:string, email_verified?:bool, name?:string, picture?:string}
     *
     * @throws RequestException
     */
    public function fetchUserInfo(string $accessToken): array
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->get(self::USERINFO_URL)
            ->throw()
            ->json();
    }

    /**
     * Revoke a token at Google's end. Best-effort; failures are not fatal
     * since the user can still revoke from their Google account page.
     */
    public function revoke(string $token): bool
    {
        try {
            return Http::asForm()
                ->post(self::REVOKE_URL, ['token' => $token])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{client_id:string, client_secret:string, redirect_uri:string, scopes:array<int,string>}
     */
    private function config(string $purpose): array
    {
        $cfg = config("services.google.{$purpose}");

        if (! is_array($cfg) || empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['redirect_uri'])) {
            throw new \RuntimeException("Google OAuth purpose '{$purpose}' is not configured. Check services.google.{$purpose} and the matching env keys.");
        }

        return $cfg;
    }
}
