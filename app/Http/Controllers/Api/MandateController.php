<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Dossier;
use App\Models\Mandate;
use App\Services\MandateService;
use App\Support\Tenancy\CabinetRule;
use Illuminate\Http\Request;

/**
 * Mandats de représentation : envoi et suivi par le cabinet,
 * lecture et signature par le client via son lien (/mandat/{token}).
 */
class MandateController extends Controller
{
    public function __construct(private MandateService $mandates) {}

    public function index(Request $request)
    {
        $request->validate(['client_id' => ['required', CabinetRule::exists('clients')]]);

        return response()->json(['success' => true, 'data' => Mandate::with('dossier:id,name')
            ->where('client_id', $request->integer('client_id'))
            ->orderByDesc('id')->get()->map(fn (Mandate $m) => $this->payload($m))->values()]);
    }

    /** Aperçu du texte qui sera envoyé à ce client. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', CabinetRule::exists('clients')],
            'dossier_id' => ['nullable', CabinetRule::exists('dossiers')],
        ]);
        $client = Client::findOrFail($data['client_id']);
        $dossier = isset($data['dossier_id']) ? Dossier::where('client_id', $client->id)->findOrFail($data['dossier_id']) : null;

        return response()->json(['success' => true, 'data' => [
            'title' => MandateService::DEFAULT_TITLE,
            'body' => $this->mandates->render(Cabinet::findOrFail($client->cabinet_id), $client, $dossier),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', CabinetRule::exists('clients')],
            'dossier_id' => ['nullable', CabinetRule::exists('dossiers')],
            'title' => ['nullable', 'string', 'max:150'],
        ]);
        $client = Client::findOrFail($data['client_id']);
        if (! $client->email) {
            return response()->json(['success' => false, 'message' => 'Ce client n\'a pas d\'adresse courriel.'], 422);
        }
        $dossier = isset($data['dossier_id']) ? Dossier::where('client_id', $client->id)->findOrFail($data['dossier_id']) : null;

        $mandate = $this->mandates->send($client, $dossier, null, $request->user('admin')->id, $data['title'] ?? null);

        return response()->json(['success' => true, 'message' => 'Mandat envoyé au client.', 'data' => $this->payload($mandate->load('dossier:id,name'))], 201);
    }

    public function void(int $id)
    {
        $mandate = Mandate::findOrFail($id);
        if ($mandate->status === 'signed') {
            return response()->json(['success' => false, 'message' => 'Un mandat signé ne peut pas être annulé ici : le client doit le révoquer par écrit.'], 422);
        }
        $mandate->forceFill(['status' => 'void'])->save();

        return response()->json(['success' => true, 'data' => $this->payload($mandate)]);
    }

    /** Lecture par le client (cabinet résolu par cabinet.code:mandate). */
    public function publicShow(string $token)
    {
        $m = Mandate::with('client')->where('public_token', $token)->firstOrFail();

        return response()->json(['success' => true, 'data' => [
            'cabinet' => Cabinet::find($m->cabinet_id)?->name,
            'branding' => Cabinet::find($m->cabinet_id)?->branding(),
            'client_name' => trim(($m->client?->first_name ?? '') . ' ' . ($m->client?->last_name ?? '')),
            'title' => $m->title,
            'body' => $m->body,
            'body_hash' => $m->body_hash,
            'status' => $m->status,
            'signed_name' => $m->signed_name,
            'signed_at' => $m->signed_at?->toIso8601String(),
        ]]);
    }

    public function sign(Request $request, string $token)
    {
        $data = $request->validate([
            'signed_name' => ['required', 'string', 'min:3', 'max:150'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Cochez la case pour confirmer que vous signez électroniquement ce mandat.',
        ]);

        $mandate = Mandate::where('public_token', $token)->firstOrFail();
        if ($mandate->status !== 'sent') {
            return response()->json(['success' => false, 'message' => $mandate->status === 'signed'
                ? 'Ce mandat est déjà signé.' : 'Ce mandat a été annulé par le cabinet.'], 422);
        }

        try {
            $this->mandates->sign($mandate, $data['signed_name'], $request->ip(), $request->userAgent());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->publicShow($token);
    }

    private function payload(Mandate $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'status' => $m->status,
            'dossier' => $m->dossier?->name,
            'appointment_id' => $m->appointment_id,
            'sent_at' => $m->sent_at?->toIso8601String(),
            'signed_at' => $m->signed_at?->toIso8601String(),
            'signed_name' => $m->signed_name,
            'signed_ip' => $m->signed_ip,
            'signed_user_agent' => $m->signed_user_agent,
            'body' => $m->body,
            'body_hash' => $m->body_hash,
            'url' => '/mandat/' . $m->public_token,
        ];
    }
}
