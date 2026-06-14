<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            // Liste de fichiers (uuid.ext) stockés dans storage/app/lock-screen/{admin_id}/
            $table->json('lock_screen_backgrounds')->nullable()->after('is_active');
            // Intervalle de rotation (secondes). 0 = pas de rotation.
            $table->unsignedSmallInteger('lock_screen_interval')->default(8)->after('lock_screen_backgrounds');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['lock_screen_backgrounds', 'lock_screen_interval']);
        });
    }
};
