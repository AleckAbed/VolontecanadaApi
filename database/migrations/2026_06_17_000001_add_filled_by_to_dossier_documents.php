<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_documents', function (Blueprint $table) {
            // Qui a effectué la dernière sauvegarde : 'admin' | 'collab' | 'client' | null
            $table->string('filled_by', 20)->nullable()->after('filled_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_documents', function (Blueprint $table) {
            $table->dropColumn('filled_by');
        });
    }
};
