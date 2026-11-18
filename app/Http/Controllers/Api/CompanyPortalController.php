<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesAuthCookie;
use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\CompanyContact;
use App\Models\CompanyRequest;
use App\Models\Dossier;
use App\Models\Invoice;
use App\Services\NotificationService;
use App\Support\AdminAccessTokens;
use App\Support\Audit;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Espace entreprise : un contact de l'entreprise suit les dossiers de ses employés
 * (selon ce que le cabinet partage pour chaque dossier), demande l'ouverture d'un dossier
 * pour un nouvel employé et consulte les factures adressées à l'entreprise.
 */
class CompanyPortalController extends Controller
{
    use IssuesAuthCookie;

    // ─── Connexion et accès ─────────────────────────────────────────────────

    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);
        $contact = CompanyContact::acrossCabinets()->where('email', $request->email)->get()
            ->first(fn ($c) => $c->password && Hash::check($request->password, $c->password));
        if (! $contact) {
            throw ValidationException::withMessages(['email' => ['Les informations d’identification sont incorrectes.']]);
        }
        $company = CurrentCabinet::withoutScope(fn () => $contact->company()->first());
        if (! $contact->is_active || ! $company?->is_active) {
            throw ValidationException::withMessages(['email' => ['Cet accès est désactivé. Contactez votre cabinet.']]);
        }
        $contact->forceFill(['last_login_at' => now()])->save();
        $token = $contact->createToken('company-token')->plainTextToken;
        Audit::log('login', 'company_contact', $contact->id, [], $contact->cabinet_id, ['type' => 'company', 'id' => $contact->id, 'name' => 'Entreprise : ' . $contact->full_name]);

        return response()->json(['success' => true, 'message' => 'Connexion réussie', 'data' => ['contact' => $this->formatContact($contact), 'token' => $token]])
            ->withCookie($this->buildAuthCookie($token));
    }

    public function logout(Request $request)
    {
        $request->user('company')?->currentAccessToken()?->delete();

        return response()->json(['success' => true])->withCookie($this->buildForgetAuthCookie());
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'data' => $this->formatContact($request->user('company'))]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        CurrentCabinet::withoutScope(function () use ($request) {
            foreach (CompanyContact::acrossCabinets()->where('email', $request->email)->where('is_active', true)->get() as $contact) {
                CurrentCabinet::runAs($contact->cabinet_id, fn () => CompanyController::sendAccess($contact, $contact->password ? AdminAccessTokens::RESET : AdminAccessTokens::INVITE));
            }
        });

        return response()->json(['success' => true, 'message' => 'Si un accès existe pour ce courriel, un lien vient d’être envoyé.']);
    }

    public function checkAccess(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        $contact = AdminAccessTokens::find($request->input('token'), CompanyContact::class);
        if (! $contact) {
            return response()->json(['success' => false, 'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau à votre cabinet.'], 410);
        }

        return response()->json(['success' => true, 'data' => [
            'purpose' => $contact->access_token_purpose,
            'name' => $contact->full_name,
            'email' => $contact->email,
            'cabinet_name' => Cabinet::find($contact->cabinet_id)?->name,
        ]]);
    }

    public function completeAccess(Request $request)
    {
        $request->validate(['token' => 'required|string', 'password' => AdminAccessTokens::passwordRules()], AdminAccessTokens::passwordMessages());
        $contact = AdminAccessTokens::find($request->input('token'), CompanyContact::class);
        if (! $contact) {
            return response()->json(['success' => false, 'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau à votre cabinet.'], 410);
        }
        $contact->password = $request->input('password');
        $contact->save();
        AdminAccessTokens::consume($contact);
        $contact->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Votre accès est prêt. Vous pouvez vous connecter.', 'data' => ['email' => $contact->email]]);
    }

    // ─── Espace entreprise ──────────────────────────────────────────────────

    public function overview(Request $request)
    {
        $contact = $request->user('company');
        $company = $contact->company;
        $statuses = Dossier::statusOptions();

        // Seuls les employés actuels : un ancien employé n'est plus visible.
        $links = DB::table('company_client')->where('company_id', $company->id)->whereNull('ended_at')->get()->keyBy('client_id');
        $clients = Client::whereIn('id', $links->keys())->orderBy('last_name')->get();
        $dossiers = Dossier::whereIn('client_id', $links->keys())->where('company_id', $company->id)->orderByDesc('created_at')->get()->groupBy('client_id');

        return response()->json(['success' => true, 'data' => [
            'company' => ['name' => $company->name],
            'cabinet' => ($c = Cabinet::find($company->cabinet_id)) ? ['name' => $c->name, 'branding' => $c->branding()] : null,
            'employees' => $clients->map(fn (Client $cl) => [
                'name' => trim("{$cl->first_name} {$cl->last_name}"),
                'job_title' => $links[$cl->id]->job_title ?? null,
                'is_family' => $cl->client_type === 'family',
                'dossiers' => ($dossiers[$cl->id] ?? collect())->map(fn (Dossier $d) => $this->dossierView($d, $statuses))->values(),
            ])->values(),
            'requests' => CompanyRequest::where('company_id', $company->id)->latest()->limit(30)->get()->map(fn (CompanyRequest $r) => [
                'id' => $r->id, 'name' => trim("{$r->first_name} {$r->last_name}"), 'request_type' => $r->request_type,
                'status' => $r->status, 'response' => $r->response, 'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'invoices' => Invoice::where('company_id', $company->id)->whereNot('status', 'draft')->latest('issued_at')->limit(50)->get()->map(fn (Invoice $i) => [
                'number' => $i->number, 'status' => $i->status, 'total_cents' => $i->total_cents,
                'issued_at' => $i->issued_at?->toDateString(), 'due_date' => $i->due_date?->toDateString(), 'url' => '/facture/' . $i->public_token,
            ]),
        ]]);
    }

    /** Demande d'ouverture du dossier d'un employé : le cabinet la valide. */
    public function storeRequest(Request $request, NotificationService $notifications)
    {
        $contact = $request->user('company');
        $data = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:150',
            'request_type' => 'nullable|string|max:150',
            'with_family' => 'sometimes|boolean',
            'message' => 'nullable|string|max:2000',
            'consent' => 'accepted',
        ], ['consent.accepted' => 'Confirmez que l’employé est informé de cette demande.']);
        unset($data['consent']);
        $cr = CompanyRequest::create($data + ['company_id' => $contact->company_id, 'contact_id' => $contact->id, 'status' => 'pending']);

        $notifications->notify(
            $notifications->caseStaffIds(),
            'company_request',
            "{$contact->company->name} demande un dossier pour {$cr->first_name} {$cr->last_name}",
            $cr->request_type ?: null,
            "/admin/entreprises/{$contact->company_id}",
            "company_request:{$cr->id}",
        );

        return response()->json(['success' => true, 'message' => 'Demande envoyée au cabinet. Vous verrez sa réponse ici.'], 201);
    }

    // ─── Interne ────────────────────────────────────────────────────────────

    /** Avancement toujours ; formulaires et documents seulement si le cabinet les partage. */
    private function dossierView(Dossier $d, array $statuses): array
    {
        $invIds = DB::table('invitations')->where('dossier_id', $d->id)->pluck('id');
        $items = DB::table('invitation_items')->whereIn('invitation_id', $invIds)->get(['id', 'item_kind', 'form_type_id', 'document_template_id', 'dossier_document_id', 'status']);
        $deadlines = DB::table('deadlines')->where('dossier_id', $d->id)->whereNull('completed_at')->orderBy('due_date')->get(['label', 'due_date']);

        $view = [
            'name' => $d->name,
            'service_name' => $d->service_name,
            'status_label' => $statuses[$d->status] ?? $d->status,
            'opened_at' => $d->opened_at?->toDateString(),
            'closed' => $d->closed_at !== null,
            'progress' => ['done' => $items->where('status', 'completed')->count(), 'total' => $items->count()],
            'deadlines' => $deadlines->map(fn ($x) => ['label' => $x->label, 'due_date' => $x->due_date])->values(),
            'visibility' => $d->company_visibility,
        ];

        if ($d->company_visibility === 'full') {
            $formNames = DB::table('form_types')->whereIn('id', $items->pluck('form_type_id')->filter())->pluck('name', 'id');
            $tplNames = DB::table('document_templates')->whereIn('id', $items->pluck('document_template_id')->filter())->pluck('name', 'id');
            $docNames = DB::table('dossier_documents')->whereIn('id', $items->pluck('dossier_document_id')->filter())->pluck('name', 'id');
            $view['items'] = $items->map(fn ($i) => [
                'name' => $formNames[$i->form_type_id] ?? $tplNames[$i->document_template_id] ?? $docNames[$i->dossier_document_id] ?? 'Élément',
                'status' => $i->status,
            ])->values();
            $view['documents'] = DB::table('dossier_documents')->where('dossier_id', $d->id)->orderBy('sort_order')->get(['name', 'status'])->values();
        }

        return $view;
    }

    private function formatContact(CompanyContact $c): array
    {
        return ['id' => $c->id, 'name' => $c->full_name, 'email' => $c->email, 'company' => CurrentCabinet::withoutScope(fn () => $c->company()->first()?->name)];
    }
}
