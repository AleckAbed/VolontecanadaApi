<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->foreignId('collaborator_id')
                ->nullable()
                ->after('family_member_id')
                ->constrained('collaborators')
                ->nullOnDelete();
            $table->boolean('allow_collab_uploads')->default(true)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collaborator_id');
            $table->dropColumn('allow_collab_uploads');
        });
    }
};
