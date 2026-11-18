<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients « entreprise » (employeurs) : contacts avec un espace entreprise, employés liés
 * (avec historique), dossiers demandés par l'entreprise, demandes d'ouverture de dossier,
 * factures adressées à l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('business_number', 40)->nullable(); // NEQ ou NE
            $table->string('sector')->nullable();
            $table->string('email')->nullable();      // facturation
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province', 2)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['cabinet_id', 'name']);
        });

        Schema::create('company_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('job_title')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('access_token_hash', 64)->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('access_token_purpose', 20)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['cabinet_id', 'email']);
        });

        // Lien employé ↔ entreprise, avec historique (ended_at rempli = ancien employeur).
        Schema::create('company_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'ended_at']);
        });

        Schema::create('company_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('company_contacts')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('job_title')->nullable();
            $table->string('request_type')->nullable(); // permis de travail, EIMT, RP…
            $table->boolean('with_family')->default(false);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending | accepted | declined
            $table->text('response')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('dossiers')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['cabinet_id', 'status']);
        });

        Schema::table('dossiers', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('client_id')->constrained('companies')->nullOnDelete();
            // Ce que l'entreprise voit de ce dossier : progress (avancement) | full (aussi formulaires et documents).
            $table->string('company_visibility', 10)->default('progress')->after('company_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Facture adressée à l'entreprise (le client reste l'employé concerné).
            $table->foreignId('company_id')->nullable()->after('client_id')->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('company_visibility');
        });
        Schema::dropIfExists('company_requests');
        Schema::dropIfExists('company_client');
        Schema::dropIfExists('company_contacts');
        Schema::dropIfExists('companies');
    }
};
