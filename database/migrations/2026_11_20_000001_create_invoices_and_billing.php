<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S6 — Paiements.
 *
 * - cabinets : abonnement à la plateforme (par siège, période d'essai),
 *   compte Stripe Connect pour encaisser les clients, numéros de taxes,
 *   numérotation des factures.
 * - invoices / invoice_lines : factures des clients du cabinet, en cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table) {
            // Abonnement du cabinet à la plateforme
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('subscription_status', 20)->default('trialing'); // trialing | active | past_due | canceled | unpaid
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->unsignedInteger('subscribed_seats')->nullable();

            // Encaissement des clients (Stripe Connect)
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_charges_enabled')->default(false);

            // Facturation
            $table->string('gst_number', 30)->nullable();  // TPS/TVH
            $table->string('qst_number', 30)->nullable();  // TVQ
            $table->unsignedInteger('next_invoice_number')->default(1);
        });

        // Les cabinets existants démarrent leur période d'essai aujourd'hui.
        DB::table('cabinets')->update([
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays((int) env('SUBSCRIPTION_TRIAL_DAYS', 30)),
        ]);

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('dossiers')->nullOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('draft'); // draft | sent | paid | void
            $table->string('currency', 3)->default('CAD');
            $table->string('tax_province', 2)->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->json('tax_lines')->nullable();
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('public_token', 64)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable(); // card | cash | transfer | cheque | other
            $table->string('payment_reference')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cabinet_id', 'number']);
            $table->index(['cabinet_id', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedBigInteger('unit_price_cents');
            $table->boolean('taxable')->default(true);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');

        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_customer_id', 'stripe_subscription_id', 'subscription_status', 'trial_ends_at',
                'current_period_end', 'subscribed_seats', 'stripe_account_id', 'stripe_charges_enabled',
                'gst_number', 'qst_number', 'next_invoice_number',
            ]);
        });
    }
};
