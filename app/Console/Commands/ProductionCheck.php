<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Models\PlatformAdmin;
use App\Support\Security\ScannerUnavailable;
use App\Support\Security\VirusScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * « Prêt pour la production ? » : vérifie la configuration du serveur avant d'y mettre de vraies données.
 * À lancer après chaque déploiement (deploy/deploy.sh) : un ÉCHEC rend la commande en erreur.
 */
class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Vérifie que la configuration est sûre pour la production';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(VirusScanner $scanner): int
    {
        $https = fn (?string $url) => is_string($url) && str_starts_with($url, 'https://');

        // Application
        $this->check(app()->environment('production'), 'Environnement « production »', 'APP_ENV=' . app()->environment());
        $this->check(! config('app.debug'), 'Mode débogage coupé', 'APP_DEBUG doit valoir false : sinon les erreurs affichent la configuration');
        $this->check((string) config('app.key') !== '', 'Clé de l’application');
        $this->check($https(config('app.url')), 'API en HTTPS', 'APP_URL=' . config('app.url'));
        $this->check($https(config('app.frontend_url')), 'Front en HTTPS', 'FRONTEND_URL');
        $this->check(strtolower((string) env('AUTH_COOKIE_SAMESITE', 'lax')) !== 'none', 'Cookie de session protégé (SameSite)', 'AUTH_COOKIE_SAMESITE=none permet des requêtes forgées depuis un autre site');

        // Données
        $this->check(config('database.default') !== 'sqlite', 'Base MySQL (pas SQLite)', 'DB_CONNECTION=' . config('database.default'));
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $dbOk = false;
        }
        $this->check($dbOk, 'Connexion à la base', 'Base injoignable : vérifiez DB_HOST, DB_PORT et le service MySQL');
        $this->check(config('filesystems.documents') !== 'local', 'Documents dans le stockage objet', 'DOCUMENTS_DISK=' . config('filesystems.documents'));
        $this->check(config('filesystems.backups') !== 'backups_local', 'Sauvegardes hors du serveur', 'BACKUP_DISK=' . config('filesystems.backups'));
        $this->check((string) config('backup.encryption_key') !== '', 'Clé des sauvegardes', 'php artisan backup:key, avec une copie hors du serveur');

        // Courriels et file d'attente
        $this->check(config('queue.default') !== 'sync', 'File d’attente avec un worker', 'QUEUE_CONNECTION=' . config('queue.default'));
        $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Envoi réel des courriels', 'MAIL_MAILER=' . config('mail.default'));

        // Antivirus
        $this->check($scanner->enabled(), 'Antivirus configuré', 'CLAMAV_HOST');
        $this->check((bool) config('security.antivirus.required'), 'Antivirus obligatoire', 'CLAMAV_REQUIRED=true : un fichier non analysé est refusé');
        if ($scanner->enabled()) {
            try {
                $this->check($scanner->scanBytes('volonte-canada production-check') === null, 'Antivirus joignable');
            } catch (ScannerUnavailable $e) {
                $this->check(false, 'Antivirus joignable', $e->getMessage());
            }
        }

        // Comptes
        $this->warn_((string) config('sentry.dsn') !== '', 'Suivi des erreurs (Sentry ou GlitchTip)', 'SENTRY_LARAVEL_DSN');
        if ($dbOk) {
            $this->warn_(PlatformAdmin::query()->get()->every->hasTwoFactor(), 'Double authentification de la console', 'php artisan platform:two-factor');
            $without = Cabinet::where('require_two_factor', false)->pluck('name');
            $this->warn_($without->isEmpty(), 'Double authentification exigée par les cabinets', 'Non exigée : ' . $without->join(', '));
        }
        $this->warn_(app()->configurationIsCached(), 'Configuration en cache', 'php artisan config:cache');

        $this->table(['État', 'Vérification', 'Détail'], $this->rows);
        $failures = collect($this->rows)->where(0, 'ÉCHEC')->count();
        $warnings = collect($this->rows)->where(0, 'ATTENTION')->count();

        if ($failures > 0) {
            $this->error("{$failures} échec(s) : ne mettez pas de vraies données sur ce serveur avant de les corriger.");

            return self::FAILURE;
        }
        $this->info($warnings > 0 ? "Prêt, avec {$warnings} point(s) à surveiller." : 'Prêt pour la production.');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $detail = ''): void
    {
        $this->rows[] = [$ok ? 'OK' : 'ÉCHEC', $label, $ok ? '' : $detail];
    }

    private function warn_(bool $ok, string $label, string $detail = ''): void
    {
        $this->rows[] = [$ok ? 'OK' : 'ATTENTION', $label, $ok ? '' : $detail];
    }
}
