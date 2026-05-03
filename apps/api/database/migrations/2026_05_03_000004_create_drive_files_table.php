<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_account_id')
                ->constrained('connected_google_accounts')
                ->cascadeOnDelete();
            // Denormalized for fast user-scoped queries without joining account.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('ebook_categories')
                ->nullOnDelete();
            $table->string('drive_file_id');
            $table->string('name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('md5_checksum')->nullable();
            $table->string('parent_folder_id')->nullable();
            $table->string('parent_folder_path')->nullable();
            $table->text('web_view_link')->nullable();
            $table->string('cover_thumb_url')->nullable();
            $table->timestamp('drive_modified_time')->nullable();
            $table->boolean('trashed')->default(false);
            // pdf | epub | chm | djvu | other
            $table->string('format', 16)->default('other');
            // Set when the user removes from the app library only (Drive intact).
            $table->timestamp('removed_from_library_at')->nullable();
            $table->timestamps();

            $table->unique(['connected_account_id', 'drive_file_id']);
            $table->index(['user_id', 'format']);
            $table->index(['user_id', 'md5_checksum']);
            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
    }
};
