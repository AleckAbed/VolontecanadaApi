<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // any = tous, in_canada = clients au Canada, outside_canada = clients hors Canada
            $table->string('target_location', 20)->default('any')->after('service_name');
            $table->index('target_location');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropIndex(['target_location']);
            $table->dropColumn('target_location');
        });
    }
};
