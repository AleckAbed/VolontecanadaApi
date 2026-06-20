<?php

namespace Database\Seeders;

use App\Models\ImmigrationService;
use Illuminate\Database\Seeder;

/**
 * Seeder des 6 services d'immigration par défaut du cabinet.
 *
 * Idempotent : utilise `updateOrCreate` sur le `name` comme clé unique
 * (la colonne `name` a une contrainte UNIQUE). Tu peux donc relancer ce
 * seeder autant de fois que tu veux sans créer de doublons — seuls les
 * services manquants seront ajoutés, et ceux existants seront mis à jour
 * sur description / category / duration / color / sort_order si tu les
 * modifies ici.
 *
 * Usage :
 *   php artisan db:seed --class=ImmigrationServiceSeeder
 */
class ImmigrationServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'name' => 'Visa de Visiteur',
                'description' => 'Visa temporaire pour visiter le Canada',
                'category' => 'Visa',
                'duration' => '2-4 semaines',
                'color' => '#2465FF',
                'sort_order' => 1,
            ],
            [
                'name' => 'Permis de Travail',
                'description' => 'Permis pour travailler au Canada',
                'category' => 'Travail',
                'duration' => '4-8 semaines',
                'color' => '#F5A623',
                'sort_order' => 2,
            ],
            [
                'name' => 'Résidence Permanente',
                'description' => 'Demande de résidence permanente',
                'category' => 'Immigration',
                'duration' => '6-12 mois',
                'color' => '#11A849',
                'sort_order' => 3,
            ],
            [
                'name' => 'Citoyenneté Canadienne',
                'description' => 'Demande de citoyenneté',
                'category' => 'Citoyenneté',
                'duration' => '12-18 mois',
                'color' => '#8A63D2',
                'sort_order' => 4,
            ],
            [
                'name' => 'Parrainage Familial',
                'description' => 'Parrainage de membres de la famille',
                'category' => 'Famille',
                'duration' => '12-24 mois',
                'color' => '#FF1A1A',
                'sort_order' => 5,
            ],
            [
                'name' => 'Visa Étudiant',
                'description' => 'Permis d\'études pour le Canada',
                'category' => 'Éducation',
                'duration' => '4-6 semaines',
                'color' => '#0070F3',
                'sort_order' => 6,
            ],
        ];

        foreach ($services as $svc) {
            ImmigrationService::updateOrCreate(
                ['name' => $svc['name']],
                [
                    'description' => $svc['description'],
                    'category' => $svc['category'],
                    'duration' => $svc['duration'],
                    'color' => $svc['color'],
                    'status' => 'active',
                    'sort_order' => $svc['sort_order'],
                ]
            );
        }

        $this->command->info(sprintf('%d services d\'immigration vérifiés / créés.', count($services)));
    }
}
