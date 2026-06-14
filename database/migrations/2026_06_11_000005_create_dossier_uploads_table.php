<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fichiers libres téléversés par le collaborateur sur un dossier
     * (preuves, scans, justificatifs internes…). Visible par l'admin.
     */
    public function up(): void
    {
        Schema::create('dossier_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('collaborator_id')->nullable()->constrained('collaborators')->nullOnDelete();
            $table->string('label');
            $table->string('original_filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path');
            $table->timestamps();

            $table->index('dossier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_uploads');
    }
};
