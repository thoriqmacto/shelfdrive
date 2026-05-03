<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drive_file_id')
                ->constrained('drive_files')
                ->cascadeOnDelete();
            // Mirror of drive_files.format at write time so the locator can be
            // interpreted without joining.
            $table->string('format', 16);
            // PDF / DJVU page number.
            $table->unsignedInteger('page')->nullable();
            // EPUB CFI string.
            $table->string('cfi')->nullable();
            // CHM topic path.
            $table->string('chm_topic')->nullable();
            $table->decimal('percent', 5, 2)->default(0);
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};
