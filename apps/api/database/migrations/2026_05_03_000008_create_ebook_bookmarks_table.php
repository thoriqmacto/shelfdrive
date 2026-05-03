<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebook_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drive_file_id')
                ->constrained('drive_files')
                ->cascadeOnDelete();
            $table->string('format', 16);
            $table->unsignedInteger('page')->nullable();
            $table->string('cfi')->nullable();
            $table->string('chm_topic')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'drive_file_id']);
            // For the global /bookmarks list view ordered by recency.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebook_bookmarks');
    }
};
