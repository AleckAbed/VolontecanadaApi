<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents de base rattachés directement à un dossier.
     * Remplis par le collaborateur assigné — JAMAIS envoyés au client.
     */
    public function up(): void
    {
        Schema::create('dossier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // PDF source (template vierge)
            $table->string('template_path');
            // Optionnel : schéma de formulaire si on génère un wrapper React (peu utilisé ici)
            $table->json('schema')->nullable();
            // Données saisies par le collaborateur
            $table->json('form_data')->nullable();
            // PDF rempli (généré à partir de form_data via le viewer)
            $table->string('filled_pdf_path')->nullable();
            // Statut : in_progress | completed
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('dossier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_documents');
    }
};
