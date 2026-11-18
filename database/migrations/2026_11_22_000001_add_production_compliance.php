<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S8 : rappels de rendez-vous, IA désactivée par défaut (2.3),
 * conservation et destruction des dossiers (3.9), droits du client (3.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('cancelled_at');
        });

        // 2.3 : l'assistant IA envoie les questions à un fournisseur hors Canada.
        // Désactivé tant qu'un gestionnaire du cabinet ne l'a pas activé en connaissance de cause.
        Schema::table('chatbot_settings', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->after('id');
            $table->timestamp('enabled_at')->nullable()->after('enabled');
            $table->unsignedBigInteger('enabled_by')->nullable()->after('enabled_at');
        });

        // 3.9 : durée de conservation des dossiers fermés (null = conservés sans limite).
        Schema::table('cabinets', function (Blueprint $table) {
            $table->unsignedTinyInteger('retention_years')->nullable()->after('mandate_text');
        });

        Schema::table('dossiers', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('deadline_at');
            $table->boolean('retention_hold')->default(false)->after('closed_at');
            $table->timestamp('retention_notified_at')->nullable()->after('retention_hold');
            $table->index(['cabinet_id', 'closed_at']);
        });
        DB::table('dossiers')
            ->whereIn('status', ['accorde', 'refuse', 'rejete', 'annule'])
            ->update(['closed_at' => DB::raw('updated_at')]);

        // 3.10 : client effacé dont on garde seulement l'identité pour les factures (obligation fiscale).
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable();
        });

        Schema::create('data_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('type', 20)->default('erasure');
            $table->string('client_name');
            $table->string('client_email');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending | completed | rejected
            $table->text('response')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['cabinet_id', 'status']);
        });

        // Registre des destructions : preuve de ce qui a été détruit, quand et pourquoi,
        // sans conserver de renseignement personnel.
        Schema::create('deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20); // dossier | client
            $table->unsignedBigInteger('subject_id');
            $table->string('label');
            $table->string('reason', 30); // retention | erasure_request
            $table->unsignedInteger('files_deleted')->default(0);
            $table->foreignId('performed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['cabinet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_logs');
        Schema::dropIfExists('data_requests');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('erased_at'));
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropIndex(['cabinet_id', 'closed_at']);
            $table->dropColumn(['closed_at', 'retention_hold', 'retention_notified_at']);
        });
        Schema::table('cabinets', fn (Blueprint $t) => $t->dropColumn('retention_years'));
        Schema::table('chatbot_settings', fn (Blueprint $t) => $t->dropColumn(['enabled', 'enabled_at', 'enabled_by']));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('reminder_sent_at'));
    }
};
