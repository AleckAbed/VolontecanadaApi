<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('questionnaire_requests', function (Blueprint $table) {
            $table->id();
            $table->string('unique_code', 32)->unique(); // Code unique pour accéder au formulaire
            $table->enum('client_type', ['existing', 'custom'])->default('existing');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('cascade');
            $table->string('custom_name')->nullable(); // Nom si client personnalisé
            $table->string('email'); // Email du destinataire
            $table->string('phone')->nullable(); // Téléphone si client personnalisé
            $table->string('form_type')->default('questionnaire_demandeur_001'); // Type de formulaire
            $table->enum('status', ['pending', 'in_progress', 'completed', 'expired'])->default('pending');
            $table->json('form_data')->nullable(); // Données du formulaire partiellement/complètement rempli
            $table->timestamp('sent_at')->nullable(); // Date d'envoi
            $table->timestamp('expires_at')->nullable(); // Date d'expiration (14 jours après l'envoi)
            $table->timestamp('completed_at')->nullable(); // Date de complétion
            $table->foreignId('sent_by')->nullable()->constrained('admins')->onDelete('set null'); // Admin qui a envoyé
            $table->timestamps();
            
            $table->index('unique_code');
            $table->index('email');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questionnaire_requests');
    }
};

