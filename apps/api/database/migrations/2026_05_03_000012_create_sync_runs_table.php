<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_account_id')
                ->constrained('connected_google_accounts')
                ->cascadeOnDelete();
            $table->enum('kind', ['full', 'incremental', 'manual']);
            $table->enum('status', ['running', 'success', 'error', 'partial'])->default('running');
            $table->unsignedInteger('files_seen')->default(0);
            $table->unsignedInteger('files_added')->default(0);
            $table->unsignedInteger('files_updated')->default(0);
            $table->unsignedInteger('files_removed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['connected_account_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
