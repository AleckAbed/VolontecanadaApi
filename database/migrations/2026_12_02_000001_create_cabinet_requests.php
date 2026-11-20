<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'ouverture de cabinet : envoyées depuis le site public, étudiées puis
 * acceptées (le cabinet est créé et son propriétaire invité) ou refusées dans la
 * console de la plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinet_requests', function (Blueprint $table) {
            $table->id();
            $table->string('cabinet_name', 150);
            $table->string('province', 2)->nullable();
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('team_size', 20)->nullable();
            $table->string('license_number', 40)->nullable(); // numéro CICC (CRIC), facultatif
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | declined
            $table->timestamp('consent_at');
            $table->string('ip', 45)->nullable();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_requests');
    }
};
