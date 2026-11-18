<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue de modèles partagé par la plateforme : un formulaire (IRCC, MIFI…) dont le
 * préremplissage est déjà relié, que chaque cabinet importe en un clic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('service_name')->nullable();
            $table->string('target_location', 20)->default('any');
            $table->string('doc_type', 10)->default('ircc');
            $table->string('category', 20)->default('ircc');
            $table->string('pdf_path');
            $table->json('field_map')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('imports_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('catalog_template_id')->nullable()->after('field_map')->constrained('catalog_templates')->nullOnDelete();
            $table->unsignedInteger('catalog_version')->nullable()->after('catalog_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_template_id');
            $table->dropColumn('catalog_version');
        });
        Schema::dropIfExists('catalog_templates');
    }
};
