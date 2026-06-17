<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // doc_type : 'ircc' (gouvernemental fédéral) ou 'fo' (provincial)
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('doc_type', 10)->default('ircc')->after('target_location');
            $table->index('doc_type');
        });
        Schema::table('dossier_documents', function (Blueprint $table) {
            $table->string('doc_type', 10)->default('ircc')->after('document_template_id');
            $table->index('doc_type');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropIndex(['doc_type']);
            $table->dropColumn('doc_type');
        });
        Schema::table('dossier_documents', function (Blueprint $table) {
            $table->dropIndex(['doc_type']);
            $table->dropColumn('doc_type');
        });
    }
};
