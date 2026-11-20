<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Remet à zéro la base des tests de bout en bout (Playwright) et y place les données de départ.
 *
 * Garde-fou : refuse de tourner hors de l'environnement « e2e » ou sur autre chose que le
 * fichier SQLite database/e2e.sqlite, pour ne jamais vider la base de travail.
 */
class ResetE2eDatabase extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'Vide la base de test de bout en bout et y remet les données de départ';

    public function handle(): int
    {
        $database = (string) config('database.connections.sqlite.database');
        if (app()->environment() !== 'e2e' || config('database.default') !== 'sqlite' || basename($database) !== 'e2e.sqlite') {
            $this->error('Refusé : cette commande ne tourne qu\'avec APP_ENV=e2e et la base database/e2e.sqlite.');

            return self::FAILURE;
        }

        if (! file_exists($database)) {
            touch($database);
        }

        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true, '--seeder' => 'Database\\Seeders\\E2eSeeder'], $this->output);
        $this->info('Base e2e prête.');

        return self::SUCCESS;
    }
}
