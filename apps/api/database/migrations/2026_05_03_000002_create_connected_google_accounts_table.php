<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_google_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_sub');
            $table->string('email');
            $table->string('display_name')->nullable();
            // Encrypted at rest via Eloquent `encrypted` cast (APP_KEY).
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            // 'login' = primary identity used for app sign-in.
            // 'drive' = additional account connected solely for Drive scanning.
            $table->enum('purpose', ['login', 'drive'])->default('drive');
            $table->enum('status', ['active', 'revoked', 'error'])->default('active');
            // Drive `changes.list` cursor for incremental sync.
            $table->string('start_page_token')->nullable();
            // ID of the auto-created upload folder ("ShelfDrive") in this account.
            $table->string('upload_folder_drive_id')->nullable();
            $table->timestamp('last_full_scan_at')->nullable();
            $table->timestamp('last_incremental_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'google_sub', 'purpose']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_google_accounts');
    }
};
