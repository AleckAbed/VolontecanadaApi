<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.6 : journal d'activité (qui a vu, modifié, téléchargé ou supprimé quoi, et quand).
 * 2.7 : double authentification du personnel (application ou courriel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            // admin | collaborator | client | public (lien sans compte) | system
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->string('action', 40);
            // Identifiants seulement : aucun nom de client n'est copié dans le journal.
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('dossier_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            // Noms des champs modifiés, jamais leurs valeurs.
            $table->json('fields')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cabinet_id', 'created_at']);
            $table->index(['cabinet_id', 'dossier_id']);
            $table->index(['cabinet_id', 'client_id']);
            $table->index(['cabinet_id', 'actor_type', 'actor_id']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->nullable(); // totp | email
            $table->text('two_factor_secret')->nullable();        // chiffré (APP_KEY)
            $table->text('two_factor_recovery_codes')->nullable(); // chiffré : empreintes des codes de secours
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::table('cabinets', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', fn (Blueprint $t) => $t->dropColumn('require_two_factor'));
        Schema::table('admins', fn (Blueprint $t) => $t->dropColumn(['two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        Schema::dropIfExists('audit_logs');
    }
};
