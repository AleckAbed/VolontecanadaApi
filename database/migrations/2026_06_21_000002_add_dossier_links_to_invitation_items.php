<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_items', function (Blueprint $table) {
            // Lien direct vers le document du dossier (instance partagée).
            // Permet la propagation même pour les documents sans modèle.
            $table->foreignId('dossier_document_id')
                ->nullable()
                ->after('document_template_id')
                ->constrained('dossier_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dossier_document_id');
        });
    }
};
