<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('avatar')->nullable();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->integer('followers_count')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('news_source_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->onDelete('cascade');
            $table->foreignId('source_id')->constrained('news_sources')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['admin_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_source_followers');
        Schema::dropIfExists('news_sources');
    }
};
