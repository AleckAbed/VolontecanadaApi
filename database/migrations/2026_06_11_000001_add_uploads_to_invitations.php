<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Option activée par l'admin : autoriser le client à téléverser des
        // documents complémentaires (libres, avec libellé) en plus des
        // formulaires et documents de l'invitation.
        Schema::table('invitations', function (Blueprint $table) {
            $table->boolean('allow_uploads')->default(false)->after('message');
        });

        // Fichiers libres téléversés par le client (passeport, CNI, etc.).
        // Le nombre est dynamique : le client en ajoute autant qu'il veut.
        Schema::create('invitation_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('invitations')->onDelete('cascade');
            $table->string('label');                 // libellé saisi par le client
            $table->string('original_filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0); // octets
            $table->string('path');                  // chemin Storage disk 'local'
            $table->timestamps();

            $table->index('invitation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_uploads');
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('allow_uploads');
        });
    }
};
