<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('family_member_id')->nullable()->after('client_id')
                ->constrained('family_members')->onDelete('set null');
            $table->foreignId('dossier_id')->nullable()->after('family_member_id')
                ->constrained('dossiers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeign(['family_member_id']);
            $table->dropColumn('family_member_id');
            $table->dropForeign(['dossier_id']);
            $table->dropColumn('dossier_id');
        });
    }
};
