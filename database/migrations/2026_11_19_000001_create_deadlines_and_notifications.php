<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S5 — Échéances, tableau « action requise », notifications.
 *
 * - deadlines : échéances saisies par le cabinet (dépôt, biométrie, examen
 *   médical…). Les dates limites de dossier et les expirations tirées du
 *   profil client sont déduites à la volée (DeadlineService).
 * - invitations.reviewed_at : un envoi complété par le client reste « à
 *   réviser » tant que le cabinet ne l'a pas marqué comme révisé.
 * - admin_notifications : notifications du personnel (une ligne par
 *   destinataire, dedupe_key pour ne pas répéter un même rappel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->string('type', 30)->default('other');
            $table->string('label');
            $table->date('due_date');
            $table->unsignedSmallInteger('remind_days')->default(14);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['cabinet_id', 'due_date']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
        });

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'read_at']);
            $table->unique(['admin_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['reviewed_at', 'reviewed_by']);
        });

        Schema::dropIfExists('deadlines');
    }
};
