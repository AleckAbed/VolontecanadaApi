<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Test de restauration (2.12) : la dernière sauvegarde est récente, se déchiffre
 * avec la clé et contient un export complet. Planifié chaque jour après la sauvegarde.
 */
class VerifyBackup extends Command
{
    protected $signature = 'backup:verify {name? : Sauvegarde à vérifier (par défaut la plus récente)}';

    protected $description = 'Vérifie qu’une sauvegarde est récente, déchiffrable et complète';

    public function handle(BackupService $backups): int
    {
        try {
            $list = $backups->list();
            $name = $this->argument('name') ?? ($list[0]['name'] ?? null);
            if (! $name) {
                throw new \RuntimeException('Aucune sauvegarde trouvée.');
            }
            $entry = collect($list)->firstWhere('name', $name);
            $ageHours = $entry ? (int) round((time() - $entry['time']) / 3600) : null;
            if (! $this->argument('name') && $ageHours !== null && $ageHours > config('backup.max_age_hours')) {
                throw new \RuntimeException("La dernière sauvegarde date de {$ageHours} h.");
            }

            $r = $backups->verify($name);
            $this->info(sprintf('OK : %s — %d tables, %.1f Mo une fois décompressée%s.', $name, $r['tables'], $r['bytes'] / 1048576,
                $ageHours !== null ? ", il y a {$ageHours} h" : ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::critical('Vérification de sauvegarde échouée', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
