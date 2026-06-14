<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_items', function (Blueprint $table) {
            $table->string('linked_questionnaire_code', 64)->nullable()->after('document_template_id');
            $table->index('linked_questionnaire_code');
        });

        Schema::table('questionnaire_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('invitation_item_id')->nullable()->after('id');
            $table->index('invitation_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitation_items', function (Blueprint $table) {
            $table->dropIndex(['linked_questionnaire_code']);
            $table->dropColumn('linked_questionnaire_code');
        });
        Schema::table('questionnaire_requests', function (Blueprint $table) {
            $table->dropIndex(['invitation_item_id']);
            $table->dropColumn('invitation_item_id');
        });
    }
};
