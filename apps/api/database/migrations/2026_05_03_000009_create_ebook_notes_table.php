<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebook_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drive_file_id')
                ->constrained('drive_files')
                ->cascadeOnDelete();
            $table->string('format', 16);
            $table->unsignedInteger('page')->nullable();
            $table->string('cfi')->nullable();
            $table->string('chm_topic')->nullable();
            $table->text('selection_text')->nullable();
            $table->text('body')->nullable();
            $table->string('color', 16)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebook_notes');
    }
};
