<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('file_folders')->cascadeOnDelete();
            $table->string('color', 32)->nullable();
            $table->string('lock_code_hash')->nullable(); // hashed 4-digit code (optional)
            $table->foreignId('created_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();

            $table->index('parent_id');
        });

        Schema::create('file_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('file_folders')->cascadeOnDelete();
            $table->string('name');                // display name (defaults to filename)
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path');                // path on disk (relative to storage)
            $table->foreignId('created_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();

            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_items');
        Schema::dropIfExists('file_folders');
    }
};
