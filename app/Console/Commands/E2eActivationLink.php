<?php

namespace App\Console\Commands;

use App\Services\CabinetProvisioner;
use App\Support\AdminAccessTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Tests de bout en bout : crée un cabinet neuf (2FA exigée, comme en vrai) dont le
 * propriétaire est « invité », et affiche son lien d'activation. Les courriels ne
 * partent pas en e2e (MAIL_MAILER=array) : c'est la seule façon d'obtenir le lien.
 *
 * Même garde-fou que e2e:reset : refuse de tourner hors de l'environnement e2e.
 */
class E2eActivationLink extends Command
{
    protected $signature = 'e2e:activation-link';

    protected $description = 'Crée un cabinet de test avec un propriétaire à activer et affiche le jeton (e2e seulement)';

    public function handle(CabinetProvisioner $provisioner): int
    {
        $database = (string) config('database.connections.sqlite.database');
        if (app()->environment() !== 'e2e' || config('database.default') !== 'sqlite' || basename($database) !== 'e2e.sqlite') {
            $this->error('Refusé : cette commande ne tourne qu\'avec APP_ENV=e2e et la base database/e2e.sqlite.');

            return self::FAILURE;
        }

        $suffix = Str::lower(Str::random(6));
        [, $owner] = $provisioner->create("Cabinet Activation {$suffix}", 'QC', 'Nadia Activation', "activation-{$suffix}@e2e.test", null);
        $this->line(AdminAccessTokens::issue($owner, AdminAccessTokens::INVITE));

        return self::SUCCESS;
    }
}
