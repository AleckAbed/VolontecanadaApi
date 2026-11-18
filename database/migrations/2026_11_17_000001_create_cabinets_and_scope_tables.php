<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S2 — Multi-cabinets.
 *
 * 1. Table `cabinets` + cabinet n° 1 qui reçoit toutes les données existantes.
 * 2. Colonne `cabinet_id` (NOT NULL, indexée, clé étrangère) sur chaque table métier.
 * 3. Les index uniques « métier » deviennent uniques PAR cabinet
 *    (deux cabinets peuvent avoir le même client, le même code de formulaire…).
 *
 * Restent uniques sur toute la plateforme :
 *  - admins.email (la connexion du personnel ne demande pas de choisir un cabinet) ;
 *  - les codes et jetons d'accès (invitations, questionnaires, documents,
 *    activation) : ils servent justement à retrouver le cabinet.
 */
return new class extends Migration
{
    /** Tables rattachées à un cabinet. */
    private const TABLES = [
        'admins',
        'clients',
        'family_members',
        'collaborators',
        'categories',
        'form_types',
        'document_templates',
        'immigration_services',
        'chatbot_settings',
        'dossiers',
        'dossier_documents',
        'dossier_supplementary_files',
        'dossier_uploads',
        'document_requests',
        'invitations',
        'invitation_items',
        'invitation_attachments',
        'invitation_uploads',
        'questionnaire_requests',
        'file_folders',
        'file_items',
        'news_sources',
        'news_articles',
    ];

    /** Index uniques globaux → uniques par cabinet : table => [colonnes]. */
    private const PER_CABINET_UNIQUES = [
        'clients' => [['email'], ['passport_number']],
        'collaborators' => [['email']],
        'form_types' => [['code']],
        'immigration_services' => [['name']],
        'news_articles' => [['slug']],
    ];

    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $firstCabinetId = DB::table('cabinets')->insertGetId([
            'name' => env('CABINET_DEFAULT_NAME', 'Volonté Canada'),
            'slug' => env('CABINET_DEFAULT_SLUG', 'volonte-canada'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('cabinet_id')->nullable()->after('id');
            });

            DB::table($tableName)->update(['cabinet_id' => $firstCabinetId]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('cabinet_id')->nullable(false)->change();
                $table->foreign('cabinet_id')->references('id')->on('cabinets')->cascadeOnDelete();
                $table->index('cabinet_id');
            });
        }

        foreach (self::PER_CABINET_UNIQUES as $tableName => $uniques) {
            Schema::table($tableName, function (Blueprint $table) use ($uniques) {
                foreach ($uniques as $columns) {
                    $table->dropUnique($columns);
                    $table->unique(array_merge(['cabinet_id'], $columns));
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::PER_CABINET_UNIQUES as $tableName => $uniques) {
            Schema::table($tableName, function (Blueprint $table) use ($uniques) {
                foreach ($uniques as $columns) {
                    $table->dropUnique(array_merge(['cabinet_id'], $columns));
                    $table->unique($columns);
                }
            });
        }

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['cabinet_id']);
                $table->dropIndex(['cabinet_id']);
                $table->dropColumn('cabinet_id');
            });
        }

        Schema::dropIfExists('cabinets');
    }
};
