<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\AvailabilityRule;
use App\Models\BookingService;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Dossier;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Database\Seeder;

/**
 * Données de départ des tests de bout en bout (Playwright), dans la base e2e seulement.
 *
 * Comptes de TEST (base locale jetable, jamais en production). Le même mot de passe est repris
 * dans isomorphic/apps/isomorphic/e2e/fixtures.ts.
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'E2e#Test2026!';

    public function run(): void
    {
        $cabinet = Cabinet::firstOrFail();
        $cabinet->forceFill(['name' => 'Cabinet Horizon', 'slug' => 'cabinet-horizon', 'booking_enabled' => true])->save();

        CurrentCabinet::runAs($cabinet->id, function () {
            $this->call(CategoryAndFormTypeSeeder::class);

            $owner = Admin::create(['name' => 'Sophie Propriétaire', 'email' => 'owner@e2e.test', 'password' => self::PASSWORD, 'role' => 'owner', 'is_active' => true]);

            // Réservation en ligne : une consultation gratuite, Sophie disponible du lundi au vendredi.
            BookingService::create(['host_admin_id' => $owner->id, 'name' => 'Consultation initiale', 'duration_minutes' => 30, 'price_cents' => 0, 'taxable' => false, 'meeting_mode' => 'video', 'is_active' => true]);
            foreach (range(1, 5) as $day) {
                AvailabilityRule::create(['admin_id' => $owner->id, 'weekday' => $day, 'start_time' => '09:00', 'end_time' => '17:00']);
            }
            Admin::create(['name' => 'Karim Adjoint', 'email' => 'assistant@e2e.test', 'password' => self::PASSWORD, 'role' => 'assistant', 'is_active' => true]);

            $amina = Client::create(['first_name' => 'Amina', 'last_name' => 'Diallo', 'email' => 'amina@e2e.test', 'password' => self::PASSWORD]);
            $omar = Client::create(['first_name' => 'Omar', 'last_name' => 'Haddad', 'email' => 'omar@e2e.test']);

            Dossier::create(['client_id' => $amina->id, 'scope' => 'client', 'name' => 'Permis de travail Amina', 'status' => 'en_cours']);

            $company = Company::create(['name' => 'Usinage Laval', 'email' => 'factures@usinage.e2e.test']);
            CompanyContact::create(['company_id' => $company->id, 'first_name' => 'Julie', 'last_name' => 'RH', 'email' => 'rh@usinage.e2e.test', 'password' => self::PASSWORD, 'is_active' => true]);
            $company->employees()->attach($omar->id, ['cabinet_id' => $company->cabinet_id, 'job_title' => 'Soudeur', 'started_at' => now()->toDateString()]);
            Dossier::create(['client_id' => $omar->id, 'scope' => 'client', 'name' => 'Permis de travail Omar', 'status' => 'en_cours'])
                ->forceFill(['company_id' => $company->id, 'company_visibility' => 'progress'])->save();
        });
    }
}
