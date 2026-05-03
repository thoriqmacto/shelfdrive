<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('match_strategy', ['md5', 'name_size_mime', 'name_only']);
            $table->enum('confidence', ['exact', 'likely', 'possible']);
            // 'account' = duplicates within a single connected Drive.
            // 'cross_account' = same user, multiple connected Drives.
            $table->enum('scope', ['account', 'cross_account']);
            $table->foreignId('canonical_drive_file_id')
                ->nullable()
                ->constrained('drive_files')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_groups');
    }
};
