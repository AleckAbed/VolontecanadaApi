<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Sauvegarde chiffrée quotidienne de la base (2.12). Planifiée à 2 h. */
class BackupDatabase extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Sauvegarde chiffrée de la base vers le disque des sauvegardes';

    public function handle(BackupService $backups): int
    {
        try {
            $name = $backups->create();
            $pruned = $backups->prune(config('backup.keep_days'));
            $this->info("Sauvegarde créée : {$name}" . ($pruned ? " ({$pruned} ancienne(s) supprimée(s))" : ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::critical('Sauvegarde de la base échouée', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
