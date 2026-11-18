<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CompanyAccessMail;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyRequest;
use App\Models\Dossier;
use App\Models\Invoice;
use App\Support\AdminAccessTokens;
use App\Support\Tenancy\CabinetRule;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Clients « entreprise » côté cabinet : fiche, contacts (accès à l'espace entreprise),
 * employés liés avec historique, dossiers demandés, demandes d'ouverture de dossier.
 */
class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $q = Company::query()->orderBy('name');
        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('business_number', 'like', "%{$s}%"));
        }
        $companies = $q->limit(500)->get();
        $ids = $companies->pluck('id');
        $employees = DB::table('company_client')->whereIn('company_id', $ids)->whereNull('ended_at')->selectRaw('company_id, count(*) n')->groupBy('company_id')->pluck('n', 'company_id');
        $dossiers = Dossier::whereIn('company_id', $ids)->whereNull('closed_at')->selectRaw('company_id, count(*) n')->groupBy('company_id')->pluck('n', 'company_id');
        $pending = CompanyRequest::whereIn('company_id', $ids)->where('status', 'pending')->selectRaw('company_id, count(*) n')->groupBy('company_id')->pluck('n', 'company_id');

        return response()->json(['success' => true, 'data' => $companies->map(fn (Company $c) => [
            'id' => $c->id, 'name' => $c->name, 'business_number' => $c->business_number, 'sector' => $c->sector,
            'city' => $c->city, 'is_active' => $c->is_active,
            'employees' => (int) ($employees[$c->id] ?? 0), 'open_dossiers' => (int) ($dossiers[$c->id] ?? 0), 'pending_requests' => (int) ($pending[$c->id] ?? 0),
        ])]);
    }

    public function store(Request $request)
    {
        $company = Company::create($this->validatedCompany($request));

        return response()->json(['success' => true, 'message' => 'Entreprise créée.', 'data' => ['id' => $company->id]], 201);
    }

    public function update(Request $request, int $id)
    {
        $company = Company::findOrFail($id);
        $company->update($this->validatedCompany($request, true));

        return response()->json(['success' => true, 'message' => 'Entreprise enregistrée.']);
    }

    public function destroy(int $id)
    {
        $company = Company::findOrFail($id);
        $company->delete(); // contacts, liens et demandes en cascade ; dossiers et factures gardés, sans entreprise

        return response()->json(['success' => true, 'message' => 'Entreprise supprimée. Les dossiers et les factures de ses employés sont conservés.']);
    }

    public function show(int $id)
    {
        $company = Company::with('contacts')->findOrFail($id);
        $links = DB::table('company_client')->where('company_id', $company->id)->orderByRaw('ended_at is null desc')->orderByDesc('started_at')->get();
        $clients = Client::whereIn('id', $links->pluck('client_id'))->withCount('familyMembers')->get()->keyBy('id');
        $dossiers = Dossier::with('client:id,first_name,last_name')->where('company_id', $company->id)->orderByDesc('created_at')->get();
        $statuses = Dossier::statusOptions();

        return response()->json(['success' => true, 'data' => [
            'company' => $company->only(['id', 'name', 'business_number', 'sector', 'email', 'phone', 'website', 'address', 'city', 'province', 'postal_code', 'notes', 'is_active']),
            'contacts' => $company->contacts->map(fn (CompanyContact $c) => [
                'id' => $c->id, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->email, 'phone' => $c->phone,
                'job_title' => $c->job_title, 'is_active' => $c->is_active,
                'portal' => $c->password ? 'active' : ($c->access_token_hash ? 'invited' : 'none'),
                'last_login_at' => $c->last_login_at?->toIso8601String(),
            ]),
            'employees' => $links->map(fn ($l) => [
                'link_id' => $l->id,
                'client_id' => $l->client_id,
                'name' => ($c = $clients->get($l->client_id)) ? trim("{$c->first_name} {$c->last_name}") : '—',
                'client_type' => $c?->client_type,
                'family_members' => (int) ($c?->family_members_count ?? 0),
                'job_title' => $l->job_title,
                'started_at' => $l->started_at,
                'ended_at' => $l->ended_at,
            ]),
            'dossiers' => $dossiers->map(fn (Dossier $d) => [
                'id' => $d->id, 'name' => $d->name, 'service_name' => $d->service_name,
                'client' => $d->client ? ['id' => $d->client->id, 'name' => trim("{$d->client->first_name} {$d->client->last_name}")] : null,
                'status' => $d->status, 'status_label' => $statuses[$d->status] ?? $d->status,
                'visibility' => $d->company_visibility, 'deadline_at' => $d->deadline_at?->toDateString(),
            ]),
            'requests' => CompanyRequest::with('contact:id,first_name,last_name')->where('company_id', $company->id)->orderByRaw("status = 'pending' desc")->latest()->limit(50)->get()->map(fn ($r) => $this->formatRequest($r)),
            'invoices' => Invoice::where('company_id', $company->id)->whereNot('status', 'draft')->latest('issued_at')->limit(50)->get()->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'total_cents' => $i->total_cents, 'issued_at' => $i->issued_at?->toDateString(),
            ]),
        ]]);
    }

    // ─── Contacts ───────────────────────────────────────────────────────────

    public function storeContact(Request $request, int $id)
    {
        $company = Company::findOrFail($id);
        $data = $request->validate($this->contactRules());
        if (CompanyContact::where('email', $data['email'])->exists()) {
            return response()->json(['success' => false, 'message' => 'Un contact d’entreprise utilise déjà cette adresse.'], 422);
        }
        $contact = $company->contacts()->create($data);

        return response()->json(['success' => true, 'message' => 'Contact ajouté.', 'data' => ['id' => $contact->id]], 201);
    }

    public function updateContact(Request $request, int $id, int $contactId)
    {
        $contact = CompanyContact::where('company_id', $id)->findOrFail($contactId);
        $data = $request->validate($this->contactRules(true) + ['is_active' => 'sometimes|boolean']);
        if (isset($data['email']) && CompanyContact::where('email', $data['email'])->whereKeyNot($contact->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Un contact d’entreprise utilise déjà cette adresse.'], 422);
        }
        $contact->update($data);
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $contact->tokens()->delete();
        }

        return response()->json(['success' => true, 'message' => 'Contact enregistré.']);
    }

    public function destroyContact(int $id, int $contactId)
    {
        $contact = CompanyContact::where('company_id', $id)->findOrFail($contactId);
        $contact->tokens()->delete();
        $contact->delete();

        return response()->json(['success' => true, 'message' => 'Contact retiré.']);
    }

    /** Envoie (ou renvoie) le lien d'accès à l'espace entreprise. */
    public function inviteContact(int $id, int $contactId)
    {
        $contact = CompanyContact::where('company_id', $id)->findOrFail($contactId);
        $purpose = $contact->password ? AdminAccessTokens::RESET : AdminAccessTokens::INVITE;

        return self::sendAccess($contact, $purpose)
            ? response()->json(['success' => true, 'message' => "Lien d’accès envoyé à {$contact->email}."])
            : response()->json(['success' => false, 'message' => 'Le courriel n’a pas pu être envoyé.'], 502);
    }

    public static function sendAccess(CompanyContact $contact, string $purpose): bool
    {
        $token = AdminAccessTokens::issue($contact, $purpose);
        try {
            Mail::to($contact->email)->send(new CompanyAccessMail($contact, Cabinet::find($contact->cabinet_id), $token, $purpose));

            return true;
        } catch (\Throwable $e) {
            Log::error('Accès espace entreprise non envoyé', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ─── Employés ───────────────────────────────────────────────────────────

    public function linkEmployee(Request $request, int $id)
    {
        $company = Company::findOrFail($id);
        $data = $request->validate([
            'client_id' => ['required', CabinetRule::exists('clients')],
            'job_title' => 'nullable|string|max:150',
            'started_at' => 'nullable|date',
        ]);
        if (DB::table('company_client')->where('company_id', $company->id)->where('client_id', $data['client_id'])->whereNull('ended_at')->exists()) {
            return response()->json(['success' => false, 'message' => 'Ce client est déjà lié à cette entreprise.'], 422);
        }
        $company->employees()->attach($data['client_id'], [
            'cabinet_id' => $company->cabinet_id, 'job_title' => $data['job_title'] ?? null, 'started_at' => $data['started_at'] ?? now()->toDateString(),
        ]);
        \App\Support\Audit::log('updated', 'company', $company->id, ['employees'], null, null, [null, (int) $data['client_id']]);

        return response()->json(['success' => true, 'message' => 'Employé lié à l’entreprise.']);
    }

    /** Fin du lien (changement d'employeur) : l'entreprise ne voit plus les dossiers de cet employé. */
    public function endEmployee(Request $request, int $id, int $linkId)
    {
        $data = $request->validate(['ended_at' => 'nullable|date']);
        $link = DB::table('company_client')->where('company_id', $id)->where('id', $linkId)->where('cabinet_id', CurrentCabinet::require())->first();
        abort_unless($link, 404);
        DB::table('company_client')->where('id', $linkId)->update(['ended_at' => $data['ended_at'] ?? now()->toDateString(), 'updated_at' => now()]);
        \App\Support\Audit::log('updated', 'company', $id, ['employees_ended'], null, null, [null, (int) $link->client_id]);

        return response()->json(['success' => true, 'message' => 'Lien terminé. L’entreprise ne voit plus les dossiers de cette personne.']);
    }

    /** Entreprises d'un client (fiche client, choix de l'entreprise d'un dossier ou d'une facture). */
    public function forClient(int $clientId)
    {
        Client::findOrFail($clientId);
        $links = DB::table('company_client')->join('companies', 'companies.id', '=', 'company_client.company_id')
            ->where('company_client.client_id', $clientId)->where('companies.cabinet_id', CurrentCabinet::require())
            ->orderByRaw('company_client.ended_at is null desc')->get(['companies.id', 'companies.name', 'company_client.job_title', 'company_client.started_at', 'company_client.ended_at']);

        return response()->json(['success' => true, 'data' => $links]);
    }

    // ─── Demandes des entreprises ───────────────────────────────────────────

    public function requests(Request $request)
    {
        $q = CompanyRequest::with(['company:id,name', 'contact:id,first_name,last_name'])->orderByRaw("status = 'pending' desc")->latest();
        if ($request->query('status')) {
            $q->where('status', $request->query('status'));
        }

        return response()->json(['success' => true, 'data' => $q->limit(200)->get()->map(fn ($r) => $this->formatRequest($r) + ['company' => ['id' => $r->company_id, 'name' => $r->company?->name]])]);
    }

    /** Accepte : crée (ou reprend) le client, le lie à l'entreprise, ouvre le dossier et invite l'employé à son portail. */
    public function acceptRequest(Request $request, int $requestId)
    {
        $data = $request->validate([
            'client_id' => ['nullable', CabinetRule::exists('clients')],
            'dossier_name' => 'nullable|string|max:255',
            'service_name' => 'nullable|string|max:255',
            'visibility' => ['nullable', Rule::in(['progress', 'full'])],
            'invite' => 'sometimes|boolean',
        ]);
        $cr = CompanyRequest::where('status', 'pending')->findOrFail($requestId);

        [$client, $dossier] = DB::transaction(function () use ($cr, $data, $request) {
            $client = ! empty($data['client_id']) ? Client::findOrFail($data['client_id'])
                : (Client::where('email', $cr->email)->first() ?? Client::create([
                    'first_name' => $cr->first_name, 'last_name' => $cr->last_name, 'email' => $cr->email, 'phone' => $cr->phone,
                    'client_type' => $cr->with_family ? 'family' : 'single', 'password' => null,
                ]));
            $active = DB::table('company_client')->where('company_id', $cr->company_id)->where('client_id', $client->id)->whereNull('ended_at')->exists();
            if (! $active) {
                Company::findOrFail($cr->company_id)->employees()->attach($client->id, [
                    'cabinet_id' => $cr->cabinet_id, 'job_title' => $cr->job_title, 'started_at' => now()->toDateString(),
                ]);
            }
            $dossier = ! empty($data['dossier_name']) ? Dossier::create([
                'client_id' => $client->id, 'scope' => $client->client_type === 'family' ? 'family' : 'client',
                'name' => $data['dossier_name'], 'service_name' => $data['service_name'] ?? $cr->request_type,
                'status' => 'en_cours', 'opened_at' => now()->toDateString(),
            ]) : null;
            if ($dossier) {
                $dossier->forceFill(['company_id' => $cr->company_id, 'company_visibility' => $data['visibility'] ?? 'progress'])->save();
            }
            $cr->forceFill(['status' => 'accepted', 'client_id' => $client->id, 'dossier_id' => $dossier?->id, 'handled_by' => $request->user()->id, 'handled_at' => now()])->save();

            return [$client, $dossier];
        });

        $invited = false;
        if ($request->boolean('invite', true) && $client->email && ! $client->password) {
            $invited = app(ClientAccessController::class)->invite($client->id)->getData(true)['success'] ?? false;
        }

        return response()->json(['success' => true, 'message' => 'Demande acceptée : client lié à l’entreprise' . ($dossier ? ', dossier ouvert' : '') . ($invited ? ', employé invité à son portail.' : '.'),
            'data' => ['client_id' => $client->id, 'dossier_id' => $dossier?->id]]);
    }

    public function declineRequest(Request $request, int $requestId)
    {
        $data = $request->validate(['response' => 'required|string|min:5|max:2000']);
        $cr = CompanyRequest::where('status', 'pending')->findOrFail($requestId);
        $cr->forceFill(['status' => 'declined', 'response' => $data['response'], 'handled_by' => $request->user()->id, 'handled_at' => now()])->save();

        return response()->json(['success' => true, 'message' => 'Demande refusée. L’entreprise voit votre réponse dans son espace.']);
    }

    // ─── Interne ────────────────────────────────────────────────────────────

    private function validatedCompany(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:255",
            'business_number' => 'nullable|string|max:40',
            'sector' => 'nullable|string|max:150',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'website' => 'nullable|string|max:200',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'province' => 'nullable|string|size:2',
            'postal_code' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function contactRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return [
            'first_name' => "{$req}|string|max:120",
            'last_name' => "{$req}|string|max:120",
            'email' => "{$req}|email|max:190",
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:150',
        ];
    }

    private function formatRequest(CompanyRequest $r): array
    {
        return [
            'id' => $r->id, 'first_name' => $r->first_name, 'last_name' => $r->last_name, 'email' => $r->email, 'phone' => $r->phone,
            'job_title' => $r->job_title, 'request_type' => $r->request_type, 'with_family' => $r->with_family, 'message' => $r->message,
            'status' => $r->status, 'response' => $r->response, 'client_id' => $r->client_id, 'dossier_id' => $r->dossier_id,
            'contact' => $r->contact ? trim("{$r->contact->first_name} {$r->contact->last_name}") : null,
            'created_at' => $r->created_at?->toIso8601String(), 'handled_at' => $r->handled_at?->toIso8601String(),
        ];
    }

    /**
     * Rattache un dossier à une entreprise (ou le détache) et choisit ce qu'elle en voit :
     * « progress » (avancement) ou « full » (aussi formulaires et documents). L'employé en est
     * informé dans son portail ; le changement est inscrit au journal d'activité.
     */
    public function setDossierCompany(Request $request, int $dossierId)
    {
        $dossier = Dossier::findOrFail($dossierId);
        $data = $request->validate([
            'company_id' => ['nullable', CabinetRule::exists('companies')],
            'company_visibility' => ['sometimes', Rule::in(['progress', 'full'])],
        ]);
        if (! empty($data['company_id']) && ! DB::table('company_client')->where('company_id', $data['company_id'])->where('client_id', $dossier->client_id)->whereNull('ended_at')->exists()) {
            return response()->json(['success' => false, 'message' => 'Liez d’abord le client de ce dossier à l’entreprise.'], 422);
        }
        $dossier->forceFill([
            'company_id' => $data['company_id'] ?? null,
            'company_visibility' => $data['company_visibility'] ?? $dossier->company_visibility ?? 'progress',
        ])->save();

        return response()->json(['success' => true, 'message' => empty($data['company_id'])
            ? 'Le dossier n’est plus rattaché à une entreprise.'
            : ($dossier->company_visibility === 'full'
                ? 'L’entreprise voit l’avancement, les formulaires et les documents de ce dossier. L’employé en est informé dans son portail.'
                : 'L’entreprise voit l’avancement de ce dossier.')]);
    }
}
