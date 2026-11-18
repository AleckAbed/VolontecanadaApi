<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\Roles;
use App\Support\Tenancy\CurrentCabinet;
use Database\Seeders\CategoryAndFormTypeSeeder;
use Database\Seeders\ImmigrationServiceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée un nouveau cabinet prêt à l'emploi :
 *  - le cabinet (slug unique) ;
 *  - son propriétaire ;
 *  - un catalogue de départ (catégories, types de formulaires, services
 *    d'immigration), pour ne pas démarrer sur des écrans vides.
 *
 * Les modèles PDF ne sont pas copiés : ce sont des fichiers propres à chaque cabinet.
 */
class CabinetProvisioner
{
    /**
     * @return array{0: Cabinet, 1: Admin}
     */
    public function create(string $cabinetName, ?string $province, string $ownerName, string $ownerEmail, string $ownerPassword): array
    {
        return DB::transaction(function () use ($cabinetName, $province, $ownerName, $ownerEmail, $ownerPassword) {
            $cabinet = Cabinet::create([
                'name' => $cabinetName,
                'slug' => $this->uniqueSlug($cabinetName),
                'province' => $province,
                'is_active' => true,
            ]);
            // Période d'essai gratuite (SUBSCRIPTION_TRIAL_DAYS, 30 jours par défaut).
            $cabinet->forceFill([
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays((int) config('services.stripe.trial_days', 30)),
            ])->save();

            $owner = CurrentCabinet::runAs($cabinet->id, function () use ($ownerName, $ownerEmail, $ownerPassword) {
                $owner = Admin::create([
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'password' => $ownerPassword,
                    'role' => Roles::OWNER,
                    'is_active' => true,
                ]);

                app(CategoryAndFormTypeSeeder::class)->run();
                app(ImmigrationServiceSeeder::class)->run();

                return $owner;
            });

            return [$cabinet, $owner];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'cabinet';
        $slug = $base;
        $i = 2;
        while (Cabinet::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
