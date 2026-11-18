<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use App\Models\Dossier;
use App\Services\Compliance\DataPurger;
use App\Services\NotificationService;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Conservation et destruction des dossiers fermés (3.9).
 *
 * Pour chaque cabinet qui a choisi une durée de conservation :
 *  - 30 jours avant l'échéance, les gestionnaires sont prévenus (une fois par dossier) ;
 *    ils peuvent « conserver » un dossier (retention_hold) pour le soustraire à la destruction ;
 *  - à l'échéance, le dossier, ses fichiers et ses formulaires sont détruits et la
 *    destruction est inscrite au registre.
 *
 * Planifiée chaque jour à 3 h (routes/console.php). --dry-run : liste sans rien détruire.
 */
class ApplyRetentionPolicy extends Command
{
    public const NOTICE_DAYS = 30;

    protected $signature = 'cabinet:apply-retention {--dry-run : Afficher sans détruire ni notifier}';

    protected $description = 'Détruit les dossiers fermés dont la durée de conservation est écoulée';

    public function handle(DataPurger $purger, NotificationService $notifications): int
    {
        $dry = (bool) $this->option('dry-run');
        $destroyed = 0;

        foreach (Cabinet::whereNotNull('retention_years')->where('retention_years', '>', 0)->get() as $cabinet) {
            CurrentCabinet::runAs($cabinet->id, function () use ($cabinet, $purger, $notifications, $dry, &$destroyed) {
                $cutoff = now()->subYears($cabinet->retention_years);
                $base = fn () => Dossier::whereNotNull('closed_at')->where('retention_hold', false);

                // 1. Préavis : échéance dans les 30 prochains jours.
                $upcoming = $base()->whereNull('retention_notified_at')
                    ->where('closed_at', '<=', $cutoff->copy()->addDays(self::NOTICE_DAYS))
                    ->where('closed_at', '>', $cutoff)
                    ->get();
                if ($upcoming->isNotEmpty()) {
                    $this->line("{$cabinet->name} : {$upcoming->count()} dossier(s) à détruire dans 30 jours");
                    if (! $dry) {
                        $notifications->notify(
                            $notifications->managerIds(),
                            'retention_upcoming',
                            "{$upcoming->count()} dossier(s) fermé(s) seront détruits dans " . self::NOTICE_DAYS . ' jours',
                            "Durée de conservation du cabinet : {$cabinet->retention_years} an(s). Pour en garder un, choisissez « Conserver » dans Conformité.",
                            '/admin/conformite',
                            'retention:' . now()->format('Y-m-d'),
                        );
                        Dossier::whereIn('id', $upcoming->pluck('id'))->update(['retention_notified_at' => now()]);
                    }
                }

                // 2. Destruction : échéance passée.
                foreach ($base()->where('closed_at', '<=', $cutoff)->get() as $dossier) {
                    $this->line("{$cabinet->name} : destruction du dossier n° {$dossier->id} (fermé le {$dossier->closed_at->format('Y-m-d')})");
                    if ($dry) {
                        continue;
                    }
                    try {
                        $purger->purgeDossier($dossier, 'retention');
                        $destroyed++;
                    } catch (\Throwable $e) {
                        Log::error('Destruction de dossier échouée', ['dossier_id' => $dossier->id, 'error' => $e->getMessage()]);
                    }
                }
            });
        }

        $this->info($dry ? 'Simulation terminée : rien n’a été détruit.' : "{$destroyed} dossier(s) détruit(s).");

        return self::SUCCESS;
    }
}
