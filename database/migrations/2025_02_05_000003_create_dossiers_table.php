<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('scope', 20); // client | member | family
            $table->foreignId('family_member_id')->nullable()->constrained('family_members')->nullOnDelete();
            $table->string('name'); // nom ou type du dossier (ex. Résidence permanente, CSQ)
            $table->string('status', 50)->default('en_cours'); // en_cours, soumis, accorde, refuse, etc.
            $table->date('opened_at')->nullable();
            $table->date('deadline_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers');
    }
};
