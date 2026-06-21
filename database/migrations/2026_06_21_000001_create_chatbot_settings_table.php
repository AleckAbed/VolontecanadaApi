<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_settings', function (Blueprint $table) {
            $table->id();
            // Périmètre des questions : utilisation de la plateforme (toujours activé)
            $table->boolean('allow_immigration_questions')->default(false);
            // Qui peut poser des questions d'immigration : 'admin', 'collab', 'both'
            $table->string('immigration_questions_for', 16)->default('admin');
            // Permet à l'IA de résumer un dossier (lookup par ID ou nom client)
            $table->boolean('allow_dossier_lookup')->default(false);
            // Permet à l'IA de résumer un client
            $table->boolean('allow_client_lookup')->default(false);
            // Instructions custom de l'admin (ajoutées au system prompt)
            $table->text('custom_instructions')->nullable();
            $table->timestamps();
        });

        // Insère la ligne unique des paramètres
        \DB::table('chatbot_settings')->insert([
            'allow_immigration_questions' => false,
            'immigration_questions_for' => 'admin',
            'allow_dossier_lookup' => false,
            'allow_client_lookup' => false,
            'custom_instructions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_settings');
    }
};
