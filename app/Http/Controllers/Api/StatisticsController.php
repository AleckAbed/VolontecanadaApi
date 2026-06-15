<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Collaborator;
use App\Models\DocumentTemplate;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\Invitation;
use App\Models\InvitationItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint unique qui agrège toutes les statistiques utiles au dashboard et à la
 * page Analytics du cabinet d'immigration. Une seule requête HTTP côté front,
 * 4-6 requêtes SQL agrégées côté back, donc rapide même sur quelques milliers
 * de lignes.
 */
class StatisticsController extends Controller
{
    public function overview(Request $request)
    {
        $now = Carbon::now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // ─── KPIs principaux ────────────────────────────────────────────────
        $totalClients = Client::count();
        $activeClients = Client::where('is_active', true)->count();
        $totalDossiers = Dossier::count();
        $activeDossiers = Dossier::whereIn('status', ['en_cours', 'soumis'])->count();
        $totalInvitations = Invitation::count();
        $invitationsLast30 = Invitation::where('created_at', '>=', $thirtyDaysAgo)->count();
        $completedInvitations = Invitation::where('status', 'completed')->count();
        $completionRate = $totalInvitations > 0
            ? round(($completedInvitations / $totalInvitations) * 100, 1)
            : 0.0;
        $totalCollabs = Collaborator::count();
        $activeCollabs = Collaborator::where('is_active', true)->count();
        $totalTemplates = DocumentTemplate::where('is_active', true)->count();

        // ─── Spark-charts pour les KPI : 7 derniers jours ─────────────────
        $clientsSpark = $this->countByDay(Client::class, 7, $now);
        $dossiersSpark = $this->countByDay(Dossier::class, 7, $now);
        $invitationsSpark = $this->countByDay(Invitation::class, 7, $now);
        $collabsSpark = $this->countByDay(Collaborator::class, 7, $now);

        // ─── Time series 12 mois : créations par mois ─────────────────────
        $monthly = $this->monthlyTrend($now);

        // ─── Distribution dossiers par statut ─────────────────────────────
        $dossiersByStatus = Dossier::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');
        $statusLabels = Dossier::statusOptions();
        $dossiersByStatusData = collect($statusLabels)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'count' => (int) ($dossiersByStatus[$key] ?? 0),
        ])->values();

        // ─── Distribution dossiers par service d'immigration ──────────────
        $dossiersByService = Dossier::select('service_name', DB::raw('count(*) as count'))
            ->whereNotNull('service_name')
            ->groupBy('service_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['service' => $r->service_name, 'count' => (int) $r->count]);

        // ─── Distribution clients par localisation ────────────────────────
        $inCanada = Client::where('in_canada', true)->count();
        $outsideCanada = Client::where('in_canada', false)->count();
        $unknownLocation = Client::whereNull('in_canada')->count();
        $clientsByLocation = [
            ['key' => 'in_canada', 'label' => 'Au Canada', 'count' => $inCanada],
            ['key' => 'outside_canada', 'label' => 'Hors Canada', 'count' => $outsideCanada],
            ['key' => 'unknown', 'label' => 'Non renseigné', 'count' => $unknownLocation],
        ];

        // ─── Charge des collaborateurs (top 5 par # de dossiers) ──────────
        $collabLoad = Collaborator::leftJoin('dossiers', 'dossiers.collaborator_id', '=', 'collaborators.id')
            ->select(
                'collaborators.id',
                'collaborators.first_name',
                'collaborators.last_name',
                DB::raw('COUNT(dossiers.id) as dossiers_count')
            )
            ->groupBy('collaborators.id', 'collaborators.first_name', 'collaborators.last_name')
            ->orderByDesc('dossiers_count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => trim($r->first_name . ' ' . $r->last_name),
                'count' => (int) $r->dossiers_count,
            ]);

        // ─── Distribution des items d'invitation (forms vs documents) ─────
        $itemsByKind = InvitationItem::select('item_kind', DB::raw('count(*) as count'))
            ->groupBy('item_kind')
            ->pluck('count', 'item_kind');
        $totalForms = (int) ($itemsByKind['form'] ?? 0);
        $totalDocItems = (int) ($itemsByKind['document'] ?? 0);

        // ─── Documents IRCC : remplis vs en cours ─────────────────────────
        $docsByStatus = DossierDocument::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');
        $docsCompleted = (int) ($docsByStatus['completed'] ?? 0);
        $docsInProgress = (int) ($docsByStatus['in_progress'] ?? 0);

        // ─── Dossiers récents (10 derniers) ───────────────────────────────
        $recentDossiers = Dossier::with('client:id,first_name,last_name')
            ->select('id', 'client_id', 'name', 'service_name', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'service_name' => $d->service_name,
                'status' => $d->status,
                'client_name' => $d->client
                    ? trim(($d->client->first_name ?? '') . ' ' . ($d->client->last_name ?? ''))
                    : '—',
                'created_at' => $d->created_at?->format('d/m/Y'),
            ]);

        // ─── Invitations récentes (10 dernières) ──────────────────────────
        $recentInvitations = Invitation::with('client:id,first_name,last_name')
            ->select('id', 'client_id', 'email', 'status', 'sent_at', 'email_sent', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'email' => $i->email,
                'status' => $i->status,
                'sent_at' => $i->sent_at?->format('d/m/Y') ?? $i->created_at?->format('d/m/Y'),
                'email_sent' => (bool) $i->email_sent,
                'client_name' => $i->client
                    ? trim(($i->client->first_name ?? '') . ' ' . ($i->client->last_name ?? ''))
                    : '—',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'clients' => [
                        'total' => $totalClients,
                        'active' => $activeClients,
                        'spark' => $clientsSpark,
                    ],
                    'dossiers' => [
                        'total' => $totalDossiers,
                        'active' => $activeDossiers,
                        'spark' => $dossiersSpark,
                    ],
                    'invitations' => [
                        'total' => $totalInvitations,
                        'last_30_days' => $invitationsLast30,
                        'completion_rate' => $completionRate,
                        'spark' => $invitationsSpark,
                    ],
                    'collaborators' => [
                        'total' => $totalCollabs,
                        'active' => $activeCollabs,
                        'spark' => $collabsSpark,
                    ],
                    'templates' => [
                        'total' => $totalTemplates,
                    ],
                    'documents' => [
                        'completed' => $docsCompleted,
                        'in_progress' => $docsInProgress,
                    ],
                    'items' => [
                        'forms' => $totalForms,
                        'documents' => $totalDocItems,
                    ],
                ],
                'monthly' => $monthly,
                'dossiers_by_status' => $dossiersByStatusData,
                'dossiers_by_service' => $dossiersByService,
                'clients_by_location' => $clientsByLocation,
                'collaborator_load' => $collabLoad,
                'recent_dossiers' => $recentDossiers,
                'recent_invitations' => $recentInvitations,
            ],
        ]);
    }

    /**
     * Compte les créations par jour sur N jours pour une table donnée.
     * Retourne un tableau [{ day: '12/06', count: 3 }, ...].
     */
    private function countByDay(string $modelClass, int $days, Carbon $now): array
    {
        $start = $now->copy()->subDays($days - 1)->startOfDay();
        $rows = $modelClass::select(
                DB::raw('DATE(created_at) as d'),
                DB::raw('count(*) as count')
            )
            ->where('created_at', '>=', $start)
            ->groupBy('d')
            ->pluck('count', 'd');

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $now->copy()->subDays($days - 1 - $i);
            $key = $d->format('Y-m-d');
            $result[] = [
                'day' => $d->format('d/m'),
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Time series : créations par mois sur 12 mois (clients, dossiers, invitations).
     */
    private function monthlyTrend(Carbon $now): array
    {
        $start = $now->copy()->startOfMonth()->subMonths(11);

        $countMonths = function (string $model) use ($start, $now) {
            return $model::select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as m"),
                    DB::raw('count(*) as count')
                )
                ->where('created_at', '>=', $start)
                ->groupBy('m')
                ->pluck('count', 'm');
        };

        $clients = $countMonths(Client::class);
        $dossiers = $countMonths(Dossier::class);
        $invitations = $countMonths(Invitation::class);

        $monthsFr = [1=>'Jan',2=>'Fév',3=>'Mar',4=>'Avr',5=>'Mai',6=>'Juin',7=>'Juil',8=>'Aoû',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Déc'];
        $result = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $start->copy()->addMonths($i);
            $key = $d->format('Y-m');
            $result[] = [
                'month' => $monthsFr[(int) $d->format('n')] . ' ' . $d->format('y'),
                'clients' => (int) ($clients[$key] ?? 0),
                'dossiers' => (int) ($dossiers[$key] ?? 0),
                'invitations' => (int) ($invitations[$key] ?? 0),
            ];
        }
        return $result;
    }
}
