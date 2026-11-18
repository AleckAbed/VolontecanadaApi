<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Préférences de notification par personne (dans l'application, par courriel) ; seuls les choix différents des valeurs par défaut sont gardés. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admins', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
    }
};
