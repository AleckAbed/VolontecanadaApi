<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('ircc'); // ircc, cabinet, contrat
            $table->string('pdf_path'); // chemin vers le PDF original uploadé
            $table->json('template_json')->nullable(); // schemas pdfme (positions des champs)
            $table->foreignId('created_by')->constrained('admins')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
