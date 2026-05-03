<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplicate_group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('drive_file_id')
                ->constrained('drive_files')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['duplicate_group_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_group_members');
    }
};
