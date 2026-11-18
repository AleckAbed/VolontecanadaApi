<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consultation du journal d'activité (2.6), réservée aux gestionnaires du cabinet.
 * Les noms des clients et dossiers sont lus au moment de l'affichage : le journal
 * lui-même n'en contient pas (un client effacé n'y apparaît plus par son nom).
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'action' => 'nullable|string|max:40',
            'subject_type' => 'nullable|string|max:40',
            'actor' => 'nullable|string|max:40', // "admin:3", "collaborator:2", "client", "public", "system"
            'dossier_id' => 'nullable|integer',
            'client_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
        ]);

        $q = AuditLog::query()->orderByDesc('id');
        foreach (['action', 'subject_type', 'dossier_id', 'client_id'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['actor'])) {
            [$type, $id] = array_pad(explode(':', $f['actor'], 2), 2, null);
            $q->where('actor_type', $type);
            if ($id) {
                $q->where('actor_id', (int) $id);
            }
        }
        if (! empty($f['from'])) {
            $q->where('created_at', '>=', $f['from'] . ' 00:00:00');
        }
        if (! empty($f['to'])) {
            $q->where('created_at', '<=', $f['to'] . ' 23:59:59');
        }

        $page = $q->paginate(50);
        $rows = collect($page->items());

        // Noms actuels des clients et dossiers concernés (un appel par table).
        $clientIds = $rows->pluck('client_id')->merge($rows->where('subject_type', 'client')->pluck('subject_id'))->filter()->unique();
        $dossierIds = $rows->pluck('dossier_id')->filter()->unique();
        $clients = DB::table('clients')->whereIn('id', $clientIds)->where('cabinet_id', $request->user()->cabinet_id)
            ->get(['id', 'first_name', 'last_name', 'erased_at'])->keyBy('id');
        $dossiers = DB::table('dossiers')->whereIn('id', $dossierIds)->where('cabinet_id', $request->user()->cabinet_id)
            ->get(['id', 'name'])->keyBy('id');

        $clientName = fn ($id) => ($c = $clients->get($id)) ? ($c->erased_at ? "Client n° {$id} (effacé)" : trim("{$c->first_name} {$c->last_name}")) : ($id ? "Client n° {$id} (supprimé)" : null);

        return response()->json(['success' => true, 'data' => [
            'items' => $rows->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'actor_type' => $l->actor_type,
                'actor_id' => $l->actor_id,
                'actor_name' => $l->actor_name,
                'action' => $l->action,
                'action_label' => AuditLog::ACTIONS[$l->action] ?? $l->action,
                'subject_type' => $l->subject_type,
                'subject_label' => $l->subject_type ? (AuditLog::SUBJECTS[$l->subject_type] ?? $l->subject_type) . ($l->subject_id ? " n° {$l->subject_id}" : '') : null,
                'dossier' => $l->dossier_id ? ['id' => $l->dossier_id, 'name' => $dossiers->get($l->dossier_id)?->name ?? "Dossier n° {$l->dossier_id} (supprimé)", 'exists' => $dossiers->has($l->dossier_id)] : null,
                'client' => $l->client_id ? ['id' => $l->client_id, 'name' => $clientName($l->client_id), 'exists' => $clients->has($l->client_id) && ! $clients->get($l->client_id)->erased_at] : null,
                'fields' => $l->fields,
                'ip' => $l->ip,
            ]),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'actions' => AuditLog::ACTIONS,
            'subjects' => AuditLog::SUBJECTS,
            'actors' => AuditLog::query()->select('actor_type', 'actor_id', 'actor_name')->whereIn('actor_type', ['admin', 'collaborator'])
                ->groupBy('actor_type', 'actor_id', 'actor_name')->orderBy('actor_name')->get()
                ->map(fn ($a) => ['value' => "{$a->actor_type}:{$a->actor_id}", 'label' => $a->actor_name . ($a->actor_type === 'collaborator' ? ' (collaborateur)' : '')])
                ->unique('value')->values(),
        ]]);
    }
}
