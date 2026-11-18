<?php

namespace Database\Seeders;

use App\Models\Cabinet;
use App\Models\User;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Toutes les données de démonstration sont créées dans le cabinet par défaut.
     * Les événements de modèle restent actifs : c'est eux qui remplissent cabinet_id.
     */
    public function run(): void
    {
        $cabinet = Cabinet::firstOrCreate(
            ['slug' => env('CABINET_DEFAULT_SLUG', 'volonte-canada')],
            ['name' => env('CABINET_DEFAULT_NAME', 'Volonté Canada'), 'is_active' => true]
        );

        CurrentCabinet::runAs($cabinet->id, function () {
            $this->call([
                AdminSeeder::class,
                ClientSeeder::class,
                CategoryAndFormTypeSeeder::class,
                ImmigrationServiceSeeder::class,
            ]);
        });

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
