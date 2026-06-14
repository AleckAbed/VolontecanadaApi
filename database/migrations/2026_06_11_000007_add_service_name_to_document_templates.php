<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // Service d'immigration auquel ce modèle est rattaché (libre — nom du service).
            // Remplace progressivement le champ `category` côté UI tout en restant nullable
            // pour la compat ascendante.
            $table->string('service_name')->nullable()->after('description');
            $table->index('service_name');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropIndex(['service_name']);
            $table->dropColumn('service_name');
        });
    }
};
