<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookmarkController;
use App\Http\Controllers\Api\V1\ConnectedAccountController;
use App\Http\Controllers\Api\V1\DuplicateController;
use App\Http\Controllers\Api\V1\EbookListController;
use App\Http\Controllers\Api\V1\EbookListItemController;
use App\Http\Controllers\Api\V1\EbookNoteController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\LibraryProgressController;
use App\Http\Controllers\Api\V1\SyncController;
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

        // Drive-connect callback. Public because Google redirects the
        // bare browser back here; the originating user is recovered from
        // a server-side state cache (see ConnectedAccountController).
        Route::get('/drive/oauth/callback', [ConnectedAccountController::class, 'connectCallback'])
            ->name('drive.oauth.callback');

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

        // ShelfDrive: connected Drive accounts.
        Route::get('/accounts', [ConnectedAccountController::class, 'index']);
        Route::get('/drive/oauth/start', [ConnectedAccountController::class, 'connectStart'])
            ->name('drive.oauth.start');
        Route::delete('/accounts/{account}', [ConnectedAccountController::class, 'destroy'])
            ->whereNumber('account');

        // ShelfDrive: library + sync.
        Route::get('/library', [LibraryController::class, 'index']);
        Route::get('/library/{file}', [LibraryController::class, 'show'])
            ->whereNumber('file');
        Route::get('/library/{file}/progress', [LibraryProgressController::class, 'show'])
            ->whereNumber('file');
        Route::patch('/library/{file}/progress', [LibraryProgressController::class, 'update'])
            ->whereNumber('file');
        Route::get('/library/{file}/bookmarks', [BookmarkController::class, 'indexForFile'])
            ->whereNumber('file');
        Route::post('/library/{file}/bookmarks', [BookmarkController::class, 'store'])
            ->whereNumber('file');
        Route::get('/library/{file}/notes', [EbookNoteController::class, 'indexForFile'])
            ->whereNumber('file');
        Route::post('/library/{file}/notes', [EbookNoteController::class, 'store'])
            ->whereNumber('file');
        Route::get('/sync', [SyncController::class, 'index']);
        Route::post('/sync/{account}/run', [SyncController::class, 'run'])
            ->whereNumber('account');

        // ShelfDrive: global bookmark + note views.
        Route::get('/bookmarks', [BookmarkController::class, 'indexGlobal']);
        Route::delete('/bookmarks/{bookmark}', [BookmarkController::class, 'destroy'])
            ->whereNumber('bookmark');
        Route::get('/notes', [EbookNoteController::class, 'indexGlobal']);
        Route::patch('/notes/{note}', [EbookNoteController::class, 'update'])
            ->whereNumber('note');
        Route::delete('/notes/{note}', [EbookNoteController::class, 'destroy'])
            ->whereNumber('note');

        // ShelfDrive: duplicate groups.
        Route::get('/duplicates', [DuplicateController::class, 'index']);
        Route::post('/duplicates/{group}/resolve', [DuplicateController::class, 'resolve'])
            ->whereNumber('group');

        // ShelfDrive: ebook lists / playlists.
        Route::get('/lists', [EbookListController::class, 'index']);
        Route::post('/lists', [EbookListController::class, 'store']);
        Route::get('/lists/{list}', [EbookListController::class, 'show'])
            ->whereNumber('list');
        Route::patch('/lists/{list}', [EbookListController::class, 'update'])
            ->whereNumber('list');
        Route::delete('/lists/{list}', [EbookListController::class, 'destroy'])
            ->whereNumber('list');
        Route::post('/lists/{list}/items', [EbookListItemController::class, 'store'])
            ->whereNumber('list');
        Route::delete('/lists/{list}/items/{item}', [EbookListItemController::class, 'destroy'])
            ->whereNumber('list')->whereNumber('item');

        // ShelfDrive resources still pending: /share — ship as the
        // sharing phase lands.
    });
});
