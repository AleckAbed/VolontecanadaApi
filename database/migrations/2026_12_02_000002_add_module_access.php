<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menus ouverts ou masqués : par rôle (cabinets.module_access) et par membre
 * (admins.module_access, qui l'emporte). Vide = tout ouvert, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table) {
            $table->json('module_access')->nullable();
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->json('module_access')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', fn (Blueprint $table) => $table->dropColumn('module_access'));
        Schema::table('admins', fn (Blueprint $table) => $table->dropColumn('module_access'));
    }
};
