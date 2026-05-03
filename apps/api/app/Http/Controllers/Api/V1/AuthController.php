<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\GoogleExchangeRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Models\ConnectedGoogleAccount;
use App\Models\User;
use App\Services\Google\OAuthService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // MustVerifyEmail interface — kicks off the verification email.
        $user->sendEmailVerificationNotification();

        return $this->issueTokenResponse($user, $request, status: 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->issueTokenResponse($user, $request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->presentUser($request->user()),
        ]);
    }

    public function updateMe(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->user();
        $emailChanged = $request->filled('email')
            && $request->validated('email') !== $user->email;

        $user->fill($request->validated())->save();

        // Changing email invalidates verification.
        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'user' => $this->presentUser($user->fresh()),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->validated('password'))])->save();

        // Revoke all OTHER tokens so other devices/browsers are signed out,
        // but keep the current token alive so this caller stays authenticated.
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke any existing tokens so the reset also terminates active sessions.
                $user->tokens()->delete();
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    private function issueTokenResponse(User $user, Request $request, int $status = 200): JsonResponse
    {
        $tokenName = $request->input('device_name') ?: 'auth';
        $hours = (int) config('sanctum.token_expiration_hours', 8);
        $expiresAt = $hours > 0 ? now()->addHours($hours) : null;

        $token = $user->createToken($tokenName, ['*'], $expiresAt);

        return response()->json([
            'user' => $this->presentUser($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ], $status);
    }

    public function sendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect("{$frontend}/verify-email?status=invalid");
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect("{$frontend}/verify-email?status=verified");
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * Stage 1 of the Google login flow.
     *
     * Generates a CSRF state token bound to the requested `next` redirect
     * path, stashes it in the cache for 10 minutes, and 302s the browser
     * to Google's consent screen.
     */
    public function googleStart(Request $request, OAuthService $oauth): RedirectResponse
    {
        $next = $this->safeNextPath($request->query('next'));
        $state = Str::random(40);

        Cache::put(
            $this->stateCacheKey($state),
            ['next' => $next, 'created_at' => now()->toIso8601String()],
            now()->addMinutes(10),
        );

        return redirect()->away($oauth->buildAuthUrl('login', $state));
    }

    /**
     * Stage 2 of the Google login flow.
     *
     * Validates the state token, exchanges the auth code for tokens,
     * fetches the OpenID userinfo, upserts the User row, and redirects
     * the browser back to the web app with a single-use exchange code
     * (NOT a Sanctum token) in the query string. The Sanctum token
     * itself is fetched by the web client via `googleExchange` below.
     */
    public function googleCallback(Request $request, OAuthService $oauth): RedirectResponse
    {
        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = $request->query('error');

        if ($error !== null) {
            return redirect("{$frontend}/login?google_error=".urlencode((string) $error));
        }

        $statePayload = $state === '' ? null : Cache::pull($this->stateCacheKey($state));

        if (! is_array($statePayload) || $code === '') {
            return redirect("{$frontend}/login?google_error=invalid_state");
        }

        try {
            $tokens = $oauth->exchangeCode('login', $code);
            $userInfo = $oauth->fetchUserInfo($tokens['access_token']);
        } catch (RequestException $e) {
            Log::warning('Google login exchange failed', ['status' => $e->response->status()]);

            return redirect("{$frontend}/login?google_error=exchange_failed");
        }

        if (empty($userInfo['sub']) || empty($userInfo['email'])) {
            return redirect("{$frontend}/login?google_error=invalid_userinfo");
        }

        $user = $this->upsertGoogleLoginUser($userInfo, $tokens);

        $exchangeCode = Str::random(48);
        Cache::put(
            $this->exchangeCacheKey($exchangeCode),
            ['user_id' => $user->id],
            now()->addSeconds(60),
        );

        $next = (string) ($statePayload['next'] ?? '/dashboard');
        $url = "{$frontend}/login?google_code=".urlencode($exchangeCode)
            .'&next='.urlencode($next);

        return redirect($url);
    }

    /**
     * Stage 3 of the Google login flow.
     *
     * Web client posts the single-use exchange code; we mint a Sanctum
     * token for the bound user and return the same shape as `login` /
     * `register` so existing auth adapters can store it as-is.
     */
    public function googleExchange(GoogleExchangeRequest $request): JsonResponse
    {
        $code = (string) $request->validated('code');
        $payload = Cache::pull($this->exchangeCacheKey($code));

        if (! is_array($payload) || empty($payload['user_id'])) {
            throw ValidationException::withMessages([
                'code' => ['This sign-in code is invalid or has expired.'],
            ]);
        }

        $user = User::findOrFail($payload['user_id']);

        return $this->issueTokenResponse($user, $request);
    }

    /**
     * Persist (or upsert) the User row for a Google-login identity, plus
     * its corresponding ConnectedGoogleAccount row (`purpose=login`).
     */
    private function upsertGoogleLoginUser(array $userInfo, array $tokens): User
    {
        return DB::transaction(function () use ($userInfo, $tokens) {
            $sub = (string) $userInfo['sub'];
            $email = (string) $userInfo['email'];

            // Match by google_sub first (stable), then by verified email.
            $user = User::where('google_sub', $sub)->first()
                ?? User::where('email', $email)->first();

            if (! $user) {
                $user = new User();
            }

            $user->google_sub = $sub;
            $user->email = $email;
            $user->name = $user->name ?: ($userInfo['name'] ?? $email);
            $user->avatar_url = $userInfo['picture'] ?? $user->avatar_url;
            if (! empty($userInfo['email_verified']) && ! $user->email_verified_at) {
                $user->email_verified_at = now();
            }
            $user->save();

            // Call through the HasMany relation so user_id is auto-set —
            // user_id is intentionally excluded from $fillable per the
            // starter's mass-assignment rule, so static updateOrCreate
            // would silently drop it.
            $user->connectedGoogleAccounts()->updateOrCreate(
                [
                    'google_sub' => $sub,
                    'purpose' => ConnectedGoogleAccount::PURPOSE_LOGIN,
                ],
                [
                    'email' => $email,
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

            return $user;
        });
    }

    private function stateCacheKey(string $state): string
    {
        return "google_oauth:login:state:{$state}";
    }

    private function exchangeCacheKey(string $code): string
    {
        return "google_oauth:login:exchange:{$code}";
    }

    /**
     * Defensive `next` validator. Only relative paths beginning with `/`
     * are accepted; anything else falls back to `/dashboard` to prevent
     * open-redirect via the OAuth state.
     */
    private function safeNextPath(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '/dashboard';
        }

        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return '/dashboard';
        }

        return $value;
    }
}
