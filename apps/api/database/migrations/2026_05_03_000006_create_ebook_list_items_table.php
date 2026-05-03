<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebook_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ebook_list_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('drive_file_id')
                ->constrained('drive_files')
                ->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();

            $table->unique(['ebook_list_id', 'drive_file_id']);
            $table->index(['ebook_list_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebook_list_items');
    }
};
