<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'name' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);

        // Google login (primary identity).
        // start: redirects browser to Google's consent screen.
        // callback: Google redirects here; we redirect back to web with a
        //   single-use exchange code (NOT a Sanctum token) in the query.
        // exchange: web POSTs the code to retrieve the actual Sanctum token.
        Route::get('/auth/google/start', [AuthController::class, 'googleStart'])
            ->name('auth.google.start');
        Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])
            ->name('auth.google.callback');
        Route::post('/auth/google/exchange', [AuthController::class, 'googleExchange'])
            ->name('auth.google.exchange');

        // Email verification — link target. Signed URL, no auth.
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateMe']);
        Route::patch('/me/password', [AuthController::class, 'updatePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
            ->middleware('throttle:auth');

        // ShelfDrive resources are added per phase. See routes registered in
        // routes/api.php as the /library, /accounts, /lists, /bookmarks,
        // /notes (ebook annotations), /duplicates, /sync, /share endpoints
        // come online.
    });
});
