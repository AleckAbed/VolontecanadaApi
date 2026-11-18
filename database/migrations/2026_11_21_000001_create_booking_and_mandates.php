<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S7 — Réservation publique, Google Agenda, mandat à signer en ligne.
 *
 * - cabinets : fuseau horaire, page de réservation publique, texte du mandat.
 * - booking_services : prestations réservables (durée, prix, mode).
 * - availability_rules : plages hebdomadaires de chaque membre du personnel (heure locale du cabinet).
 * - appointments : rendez-vous (UTC), paiement via une facture S6, lien de visio, événement Google.
 * - admins : connexion Google Agenda (jeton de rafraîchissement chiffré).
 * - mandates : mandat envoyé au client, signé en ligne (nom tapé + consentement + empreinte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table) {
            $table->string('timezone', 64)->default('America/Toronto');
            $table->boolean('booking_enabled')->default(false);
            $table->text('booking_intro')->nullable();
            $table->longText('mandate_text')->nullable();
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('host_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->boolean('taxable')->default(true);
            $table->string('meeting_mode', 20)->default('video'); // video | phone | in_person
            $table->string('location')->nullable();
            $table->boolean('send_mandate')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = lundi … 7 = dimanche (ISO-8601)
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['admin_id', 'weekday']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('booking_service_id')->nullable()->constrained('booking_services')->nullOnDelete();
            $table->foreignId('host_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('confirmed'); // pending_payment | confirmed | cancelled
            $table->string('meeting_mode', 20)->default('video');
            $table->string('meeting_url')->nullable();
            $table->string('google_event_id')->nullable();
            $table->string('public_token', 64)->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['host_admin_id', 'starts_at']);
            $table->index(['cabinet_id', 'starts_at']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->text('google_refresh_token')->nullable(); // chiffré (cast « encrypted »)
            $table->string('google_calendar_email')->nullable();
            $table->string('google_oauth_state', 64)->nullable();
        });

        Schema::create('mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('dossiers')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('title');
            $table->longText('body');            // texte figé au moment de l'envoi
            $table->string('body_hash', 64);     // SHA-256 du texte signé
            $table->string('status', 20)->default('sent'); // sent | signed | void
            $table->string('public_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->string('signed_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandates');
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['google_refresh_token', 'google_calendar_email', 'google_oauth_state']);
        });
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('availability_rules');
        Schema::dropIfExists('booking_services');
        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'booking_enabled', 'booking_intro', 'mandate_text']);
        });
    }
};
