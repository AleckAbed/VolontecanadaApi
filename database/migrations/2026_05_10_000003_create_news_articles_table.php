<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('thumbnail')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('source_id')->nullable()->constrained('news_sources')->onDelete('set null');
            $table->string('audio_url')->nullable();
            $table->string('read_time')->nullable(); // ex: "5 min de lecture"
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
            $table->index('is_featured');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_articles');
    }
};
