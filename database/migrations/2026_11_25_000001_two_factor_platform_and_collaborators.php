<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double authentification de la console plateforme (application, obligatoire en production)
 * et, au choix du cabinet, des collaborateurs externes (code par courriel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::table('cabinets', function (Blueprint $table) {
            $table->boolean('require_two_factor_collaborators')->default(false)->after('require_two_factor');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', fn (Blueprint $t) => $t->dropColumn('require_two_factor_collaborators'));
        Schema::table('platform_admins', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
