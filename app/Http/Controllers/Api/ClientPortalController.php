<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Dossier;
use App\Models\FamilyMember;
use App\Models\Invitation;
use App\Models\QuestionnaireRequest;
use App\Services\ClientProfileService;
use App\Support\ClientProfileFields;
use Illuminate\Http\Request;

/**
 * Portail client (client authentifié) : ses dossiers, ce qu'il reste à faire,
 * son profil d'immigration.
 */
class ClientPortalController extends Controller
{
    public function __construct(private ClientProfileService $profiles) {}

    public function overview(Request $request)
    {
        /** @var Client $client */
        $client = $request->user('client');
        $members = FamilyMember::where('client_id', $client->id)->orderBy('id')->get();

        $dossiers = Dossier::where('client_id', $client->id)->orderByDesc('created_at')->get()
            ->map(fn (Dossier $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'service_name' => $d->service_name,
                'status' => $d->status,
                'opened_at' => $d->opened_at ? \Illuminate\Support\Carbon::parse($d->opened_at)->toDateString() : null,
                'deadline_at' => $d->deadline_at ? \Illuminate\Support\Carbon::parse($d->deadline_at)->toDateString() : null,
                'for' => $d->scope === 'member'
                    ? trim(($members->firstWhere('id', $d->family_member_id)?->first_name ?? '') . ' ' . ($members->firstWhere('id', $d->family_member_id)?->last_name ?? ''))
                    : ($d->scope === 'family' ? 'Toute la famille' : 'Vous'),
            ]);

        $invitations = Invitation::with(['items.formType', 'items.documentTemplate', 'items.dossierDocument'])
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->get();

        // Statut réel des formulaires : celui du questionnaire lié.
        $qrStatus = QuestionnaireRequest::whereIn(
            'unique_code',
            $invitations->flatMap(fn ($i) => $i->items->pluck('linked_questionnaire_code'))->filter()->values()
        )->pluck('status', 'unique_code');

        $tasks = $invitations->map(function (Invitation $inv) use ($qrStatus) {
            $items = $inv->items->map(function ($item) use ($qrStatus) {
                $status = $item->linked_questionnaire_code ? ($qrStatus[$item->linked_questionnaire_code] ?? $item->status) : $item->status;

                return [
                    'name' => $item->item_kind === 'form'
                        ? ($item->formType?->name ?? 'Formulaire')
                        : ($item->documentTemplate?->name ?? $item->dossierDocument?->name ?? 'Document'),
                    'kind' => $item->item_kind,
                    'done' => $status === 'completed',
                ];
            });

            return [
                'id' => $inv->id,
                'code' => $inv->unique_code, // le client est authentifié : c'est son propre lien
                'status' => $inv->status,
                'message' => $inv->message,
                'sent_at' => $inv->sent_at?->toDateString(),
                'expires_at' => $inv->expires_at?->toDateString(),
                'is_expired' => $inv->isExpired(),
                'items_total' => $items->count(),
                'items_done' => $items->where('done', true)->count(),
                'items' => $items->values(),
            ];
        });

        $profile = $this->profiles->resolve($client);

        return response()->json([
            'success' => true,
            'data' => [
                'client' => ['first_name' => $client->first_name, 'last_name' => $client->last_name, 'email' => $client->email],
                'cabinet' => ($c = Cabinet::find($client->cabinet_id)) ? ['name' => $c->name, 'branding' => $c->branding()] : ['name' => null, 'branding' => null],
                'dossiers' => $dossiers->values(),
                'tasks' => $tasks->values(),
                'members' => $members->map(fn ($m) => ['id' => $m->id, 'name' => trim("{$m->first_name} {$m->last_name}")])->values(),
                'profile' => [
                    'filled' => count($profile),
                    'total' => count(ClientProfileFields::FIELDS),
                ],
            ],
        ]);
    }

    public function profile(Request $request)
    {
        [$client, $member] = $this->subject($request);
        $values = $this->profiles->resolve($client, $member);

        return response()->json([
            'success' => true,
            'data' => [
                'fields' => ClientProfileFields::definitions(),
                'values' => (object) $values,
                'filled' => count($values),
                'total' => count(ClientProfileFields::FIELDS),
                'for' => $member ? trim("{$member->first_name} {$member->last_name}") : trim("{$client->first_name} {$client->last_name}"),
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        [$client, $member] = $this->subject($request);

        $rules = ['values' => 'present|array'];
        foreach (ClientProfileFields::keys() as $key) {
            $rules["values.{$key}"] = 'nullable|string|max:255';
        }
        $data = $request->validate($rules);

        $this->profiles->replace($client, $member, $data['values'] ?? []);
        $values = $this->profiles->resolve($client, $member);

        return response()->json([
            'success' => true,
            'message' => 'Profil enregistré. Vos prochains formulaires seront préremplis avec ces informations.',
            'data' => ['values' => (object) $values, 'filled' => count($values)],
        ]);
    }

    /** Le client lui-même, ou l'un des membres de sa famille (?member_id=). */
    private function subject(Request $request): array
    {
        $client = $request->user('client');
        $memberId = $request->input('member_id');
        $member = $memberId ? FamilyMember::where('client_id', $client->id)->findOrFail($memberId) : null;

        return [$client, $member];
    }
}
