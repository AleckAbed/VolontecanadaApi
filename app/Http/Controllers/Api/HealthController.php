<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * État de santé de la plateforme, pour la surveillance (Uptime Kuma, etc.).
 *
 * GET /api/health → 200 {"status":"ok"} ou 503 {"status":"degraded"}.
 * Avec l'en-tête X-Health-Token égal à HEALTH_TOKEN, le détail de chaque contrôle
 * est ajouté (jamais public : il révèle l'état interne).
 */
class HealthController extends Controller
{
    /** Une tâche qui attend plus longtemps : le worker des courriels est arrêté. */
    private const QUEUE_MAX_WAIT_MINUTES = 10;

    /** Le planificateur écrit un battement chaque minute. */
    private const SCHEDULER_MAX_SILENCE_MINUTES = 5;

    public function show(Request $request, BackupService $backups)
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'backups' => $this->backups($backups),
        ];
        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        $body = ['status' => $ok ? 'ok' : 'degraded'];
        $token = (string) config('app.health_token');
        if ($token !== '' && hash_equals($token, (string) $request->header('X-Health-Token'))) {
            $body['checks'] = $checks;
        }

        return response()->json($body, $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Base injoignable'];
        }
    }

    private function queue(): array
    {
        if (config('queue.default') !== 'database') {
            return ['ok' => true, 'driver' => config('queue.default')];
        }
        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $waitMinutes = $oldest ? max(0, intdiv(now()->getTimestamp() - (int) $oldest, 60)) : 0;
            $failed24h = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

            return [
                'ok' => $waitMinutes <= self::QUEUE_MAX_WAIT_MINUTES,
                'pending' => $pending,
                'oldest_wait_minutes' => $waitMinutes,
                'failed_last_24h' => $failed24h,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'File d’attente illisible'];
        }
    }

    private function scheduler(): array
    {
        $last = Cache::get('health:scheduler');
        if (! $last) {
            // Jamais vu : en production c'est une panne, en développement le planificateur est souvent arrêté.
            return ['ok' => ! app()->isProduction(), 'last_run' => null];
        }
        $silence = intdiv(now()->getTimestamp() - (int) $last, 60);

        return ['ok' => $silence <= self::SCHEDULER_MAX_SILENCE_MINUTES, 'minutes_since_last_run' => $silence];
    }

    private function backups(BackupService $backups): array
    {
        if (! config('backup.encryption_key')) {
            return ['ok' => ! app()->isProduction(), 'configured' => false];
        }
        // Lister le stockage objet à chaque appel serait lent : résultat gardé 10 minutes.
        $latest = Cache::remember('health:last-backup', 600, function () use ($backups) {
            try {
                return $backups->list()[0]['time'] ?? 0;
            } catch (\Throwable $e) {
                return -1;
            }
        });
        if ($latest <= 0) {
            return ['ok' => false, 'configured' => true, 'error' => $latest < 0 ? 'Stockage des sauvegardes illisible' : 'Aucune sauvegarde'];
        }
        $ageHours = intdiv(now()->getTimestamp() - $latest, 3600);

        return ['ok' => $ageHours <= config('backup.max_age_hours'), 'configured' => true, 'last_backup_hours_ago' => $ageHours];
    }
}
