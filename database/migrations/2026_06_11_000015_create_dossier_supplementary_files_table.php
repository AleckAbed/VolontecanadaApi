<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fichiers supplémentaires téléversés par l'ADMIN sur un dossier.
     * Le collaborateur peut les visualiser/télécharger mais pas les modifier.
     */
    public function up(): void
    {
        Schema::create('dossier_supplementary_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
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
        Schema::dropIfExists('dossier_supplementary_files');
    }
};
