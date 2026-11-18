<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Dossier;
use App\Models\Invitation;
use App\Services\DeadlineService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tableau « action requise » : ce qui attend le cabinet aujourd'hui, pas un
 * mur de graphiques. Chaque catégorie renvoie un compteur et les éléments,
 * avec un lien vers l'écran où agir.
 */
class ActionFeedController extends Controller
{
    private const LIST_LIMIT = 8;

    public function __construct(private DeadlineService $deadlines) {}

    public function index(Request $request)
    {
        $now = Carbon::now();

        // Envois que le client a terminés et que le cabinet n'a pas encore révisés.
        $toReview = Invitation::with('client:id,first_name,last_name')
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->orderByDesc('completed_at')
            ->get();

        // Liens clients qui expirent dans les 3 jours sans être terminés : relancer.
        $expiring = Invitation::with('client:id,first_name,last_name')
            ->where('status', '!=', 'completed')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays(3)])
            ->orderBy('expires_at')
            ->get();

        // Envoyés depuis plus de 7 jours sans que le client ait commencé.
        $noAnswer = Invitation::with('client:id,first_name,last_name')
            ->where('status', 'pending')
            ->where('sent_at', '<', $now->copy()->subDays(7))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->orderBy('sent_at')
            ->get();

        // Dossiers déposés auprès des autorités, en attente de décision.
        $awaiting = Dossier::where('status', 'soumis')->orderBy('updated_at')->get(['id', 'name', 'client_id', 'service_name', 'updated_at']);
        $awaitingClients = Client::whereIn('id', $awaiting->pluck('client_id'))->get()->keyBy('id');

        $deadlines = $this->deadlines->needingAction();

        $who = fn (Invitation $i) => $i->custom_name
            ?: ($i->client ? trim("{$i->client->first_name} {$i->client->last_name}") : $i->email);

        $buckets = [
            'overdue' => [
                'label' => 'Échéances en retard',
                'severity' => 'danger',
                'items' => $deadlines->where('urgency', 'overdue')->map(fn ($d) => $this->deadlineItem($d))->values(),
            ],
            'this_week' => [
                'label' => 'Échéances cette semaine',
                'severity' => 'warning',
                'items' => $deadlines->where('urgency', 'this_week')->map(fn ($d) => $this->deadlineItem($d))->values(),
            ],
            'to_review' => [
                'label' => 'Envois complétés à réviser',
                'severity' => 'info',
                'items' => $toReview->map(fn (Invitation $i) => [
                    'title' => $who($i),
                    'subtitle' => 'A terminé son envoi' . ($i->completed_at ? ' le ' . $i->completed_at->format('Y-m-d') : ''),
                    'date' => $i->completed_at?->toDateString(),
                    'link' => "/envois/{$i->id}",
                ])->values(),
            ],
            'expiring_links' => [
                'label' => 'Liens clients qui expirent',
                'severity' => 'warning',
                'items' => $expiring->map(fn (Invitation $i) => [
                    'title' => $who($i),
                    'subtitle' => 'Lien valable jusqu’au ' . $i->expires_at->format('Y-m-d') . ' — à relancer',
                    'date' => $i->expires_at->toDateString(),
                    'link' => "/envois/{$i->id}",
                ])->values(),
            ],
            'no_answer' => [
                'label' => 'Clients sans réponse depuis 7 jours',
                'severity' => 'neutral',
                'items' => $noAnswer->map(fn (Invitation $i) => [
                    'title' => $who($i),
                    'subtitle' => 'Envoyé le ' . $i->sent_at->format('Y-m-d') . ', pas encore commencé',
                    'date' => $i->sent_at->toDateString(),
                    'link' => "/envois/{$i->id}",
                ])->values(),
            ],
            'awaiting_decision' => [
                'label' => 'En attente de décision',
                'severity' => 'neutral',
                'items' => $awaiting->map(fn (Dossier $d) => [
                    'title' => $d->name,
                    'subtitle' => trim(($awaitingClients[$d->client_id]?->first_name ?? '') . ' ' . ($awaitingClients[$d->client_id]?->last_name ?? ''))
                        . ($d->service_name ? " · {$d->service_name}" : ''),
                    'date' => $d->updated_at?->toDateString(),
                    'link' => "/admin/dossiers/{$d->id}",
                ])->values(),
            ],
            // 3.11 : rendez-vous passés depuis moins de 14 jours, sans issue notée.
            'appointments_to_document' => [
                'label' => 'Rendez-vous à documenter',
                'severity' => 'neutral',
                'items' => \App\Models\Appointment::where('status', 'confirmed')->whereNull('outcome')
                    ->whereBetween('starts_at', [now()->subDays(14), now()->subMinutes(30)])
                    ->orderByDesc('starts_at')->get()
                    ->map(fn ($a) => [
                        'title' => $a->name,
                        'subtitle' => 'Notez si le rendez-vous a eu lieu et ce qui a été convenu.',
                        'date' => $a->starts_at->toDateString(),
                        'link' => '/admin/rendez-vous?onglet=passes',
                    ])->values(),
            ],
        ];

        $out = [];
        foreach ($buckets as $key => $bucket) {
            $out[] = [
                'key' => $key,
                'label' => $bucket['label'],
                'severity' => $bucket['severity'],
                'count' => $bucket['items']->count(),
                'items' => $bucket['items']->take(self::LIST_LIMIT)->values(),
            ];
        }

        $needingAction = collect($out)->whereIn('key', ['overdue', 'this_week', 'to_review', 'expiring_links'])->sum('count');

        return response()->json([
            'success' => true,
            'data' => [
                'buckets' => $out,
                'needing_action' => $needingAction,
                'overdue' => collect($out)->firstWhere('key', 'overdue')['count'],
            ],
        ]);
    }

    /** Le cabinet a révisé l'envoi complété : il sort de « à réviser ». */
    public function markReviewed(Request $request, int $id)
    {
        $invitation = Invitation::findOrFail($id);
        $reviewed = $request->boolean('reviewed', true);
        $invitation->forceFill([
            'reviewed_at' => $reviewed ? now() : null,
            'reviewed_by' => $reviewed ? $request->user('admin')->id : null,
        ])->save();

        return response()->json(['success' => true, 'message' => $reviewed ? 'Envoi marqué comme révisé.' : 'Envoi remis à réviser.']);
    }

    private function deadlineItem(array $d): array
    {
        $when = match (true) {
            $d['days_left'] < 0 => abs($d['days_left']) . ' j de retard',
            $d['days_left'] === 0 => 'aujourd’hui',
            default => $d['days_left'] . ' j restant' . ($d['days_left'] > 1 ? 's' : ''),
        };

        return [
            'title' => $d['label'] . ($d['client'] ? " — {$d['client']['name']}" : ''),
            'subtitle' => ($d['dossier'] ? $d['dossier']['name'] . ' · ' : '') . $d['due_date'] . " ({$when})",
            'date' => $d['due_date'],
            'link' => $d['link'] ?? '/admin/echeances',
        ];
    }
}
