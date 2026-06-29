<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('invitations')->cascadeOnDelete();
            // Fichier supplémentaire du dossier exposé en lecture/téléchargement au client.
            $table->foreignId('dossier_supplementary_file_id')
                ->constrained('dossier_supplementary_files')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['invitation_id', 'dossier_supplementary_file_id'], 'inv_attach_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_attachments');
    }
};
