<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('unique_code', 64)->unique();
            $table->enum('client_type', ['existing', 'custom']);
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->string('custom_name')->nullable();
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'expired'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('sent_by')->constrained('admins')->onDelete('cascade');
            $table->boolean('email_sent')->default(false);
            $table->text('email_error')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
