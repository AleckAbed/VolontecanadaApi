<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3 — Équipe des cabinets, rôles et super-admin plateforme.
 *
 * - admins.role : owner | admin | advisor | assistant (anciens rôles convertis).
 * - admins.password devient nullable (membre invité qui n'a pas encore choisi
 *   son mot de passe) + jeton d'accès à usage unique (invitation / réinitialisation),
 *   stocké haché.
 * - cabinets : province, téléphone.
 * - platform_admins : comptes de l'éditeur de la plateforme (hors cabinets).
 */
return new class extends Migration
{
    private const ROLE_MAP = [
        'super_admin' => 'owner',
        'admin' => 'admin',
        'manager' => 'admin',
        'agent' => 'advisor',
    ];

    public function up(): void
    {
        // L'ancienne colonne était un ENUM limité aux anciens rôles : on passe en
        // texte (la liste des rôles valides est contrôlée par App\Support\Roles).
        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 20)->default('advisor')->change();
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('access_token_hash', 64)->nullable()->unique();
            $table->string('access_token_purpose', 16)->nullable(); // invite | reset
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        foreach (self::ROLE_MAP as $old => $new) {
            DB::table('admins')->where('role', $old)->update(['role' => $new]);
        }
        DB::table('admins')->whereNotIn('role', ['owner', 'admin', 'advisor', 'assistant'])->update(['role' => 'advisor']);

        // Chaque cabinet doit avoir au moins un propriétaire : à défaut, son plus ancien admin le devient.
        foreach (DB::table('cabinets')->pluck('id') as $cabinetId) {
            $hasOwner = DB::table('admins')->where('cabinet_id', $cabinetId)->where('role', 'owner')->exists();
            if (! $hasOwner) {
                $firstAdminId = DB::table('admins')->where('cabinet_id', $cabinetId)->orderBy('id')->value('id');
                if ($firstAdminId) {
                    DB::table('admins')->where('id', $firstAdminId)->update(['role' => 'owner']);
                }
            }
        }

        Schema::table('cabinets', function (Blueprint $table) {
            $table->string('province', 2)->nullable()->after('slug');
            $table->string('phone', 50)->nullable()->after('province');
        });

        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');

        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropColumn(['province', 'phone']);
        });

        DB::table('admins')->where('role', 'owner')->update(['role' => 'super_admin']);
        DB::table('admins')->where('role', 'advisor')->update(['role' => 'agent']);
        DB::table('admins')->where('role', 'assistant')->update(['role' => 'agent']);

        Schema::table('admins', function (Blueprint $table) {
            $table->dropUnique(['access_token_hash']);
            $table->dropColumn(['access_token_hash', 'access_token_purpose', 'access_token_expires_at', 'last_login_at']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'manager', 'agent'])->default('agent')->change();
        });
        // Note : admins.password reste nullable (des invités sans mot de passe peuvent exister).
    }
};
