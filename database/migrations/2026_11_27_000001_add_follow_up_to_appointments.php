<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.11 : suivi des rendez-vous dans le dossier (dossier lié, issue, notes du personnel).
 * Déplacement en ligne : nombre de déplacements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('dossier_id')->nullable()->after('client_id')->constrained('dossiers')->nullOnDelete();
            $table->string('outcome', 20)->nullable()->after('status'); // held | no_show
            $table->text('staff_notes')->nullable()->after('notes');
            $table->timestamp('outcome_at')->nullable()->after('outcome');
            $table->unsignedTinyInteger('reschedule_count')->default(0)->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dossier_id');
            $table->dropColumn(['outcome', 'staff_notes', 'outcome_at', 'reschedule_count']);
        });
    }
};
