<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('invitations')->onDelete('cascade');
            $table->enum('item_kind', ['form', 'document']);
            $table->foreignId('form_type_id')->nullable()->constrained('form_types')->onDelete('cascade');
            $table->foreignId('document_template_id')->nullable()->constrained('document_templates')->onDelete('cascade');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->json('form_data')->nullable(); // values typed by client (forms or filled XFA)
            $table->string('pdf_filled_path')->nullable(); // for documents — server-side filled PDF
            $table->integer('sort_order')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamps();

            $table->index(['invitation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_items');
    }
};
