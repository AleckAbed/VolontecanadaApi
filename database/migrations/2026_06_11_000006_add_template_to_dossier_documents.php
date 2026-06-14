<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_documents', function (Blueprint $table) {
            $table->foreignId('document_template_id')
                ->nullable()
                ->after('dossier_id')
                ->constrained('document_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dossier_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_template_id');
        });
    }
};
