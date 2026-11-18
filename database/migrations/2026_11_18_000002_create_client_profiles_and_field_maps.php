<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S4 — « Le client répond une fois ».
 *
 * - client_profiles : profil d'immigration unique d'un client (ou d'un membre
 *   de sa famille), alimenté par le questionnaire général et par le portail.
 * - document_templates.field_map : correspondance champ PDF → clé du profil,
 *   pour préremplir les formulaires.
 * - clients : lien d'accès au portail (invitation / mot de passe oublié) et
 *   mot de passe facultatif (un client peut exister sans accès au portail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained('family_members')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'family_member_id']);
            $table->index('cabinet_id');
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('field_map')->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('access_token_hash', 64)->nullable()->unique();
            $table->string('access_token_purpose', 16)->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['access_token_hash']);
            $table->dropColumn(['access_token_hash', 'access_token_purpose', 'access_token_expires_at', 'last_login_at']);
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('field_map');
        });

        Schema::dropIfExists('client_profiles');
    }
};
