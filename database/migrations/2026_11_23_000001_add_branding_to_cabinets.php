<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1.9 : image de marque du cabinet (logo et couleur) dans les courriels, le portail et les pages publiques. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('phone');
            $table->string('brand_color', 7)->nullable()->after('logo_path');
            $table->string('website')->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', fn (Blueprint $t) => $t->dropColumn(['logo_path', 'brand_color', 'website']));
    }
};
