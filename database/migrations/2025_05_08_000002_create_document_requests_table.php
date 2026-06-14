<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('document_templates')->onDelete('cascade');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->foreignId('dossier_id')->nullable()->constrained('dossiers')->onDelete('set null');
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'in_progress', 'submitted', 'validated', 'rejected'])->default('pending');
            $table->text('message')->nullable(); // message personnalisé à envoyer au client
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('sent_by')->constrained('admins')->onDelete('cascade');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('form_data')->nullable(); // données remplies par le client
            $table->string('pdf_filled_path')->nullable(); // PDF final généré
            $table->foreignId('validated_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamp('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('email_sent')->default(false);
            $table->text('email_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
