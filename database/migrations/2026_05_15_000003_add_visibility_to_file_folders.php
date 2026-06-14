<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_folders', function (Blueprint $table) {
            // 'public' = tous les admins peuvent voir
            // 'private' = uniquement le créateur + admins listés dans file_folder_permissions
            $table->string('visibility', 16)->default('public')->after('color');
        });

        Schema::create('file_folder_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('file_folders')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['folder_id', 'admin_id']);
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_folder_permissions');
        Schema::table('file_folders', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
