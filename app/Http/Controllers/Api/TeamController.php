<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\AdminAccessTokens;
use App\Support\Modules;
use App\Support\Roles;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Équipe du cabinet : liste, invitation, changement de rôle, désactivation.
 * Réservé aux propriétaires et administrateurs (middleware role:owner,admin).
 *
 * Règles :
 *  - seul un propriétaire peut nommer ou retirer un propriétaire ;
 *  - un administrateur ne peut pas modifier un propriétaire ;
 *  - personne ne peut modifier son propre rôle ni se désactiver ;
 *  - le cabinet garde toujours au moins un propriétaire actif.
 */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $members = Admin::orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'advisor' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'members' => $members->map(fn (Admin $a) => $this->format($a, $request->user('admin')))->values(),
                // Sièges = comptes actifs du personnel (base de la facturation en S6).
                'seats_used' => $members->where('is_active', true)->count(),
                'roles' => collect(Roles::ALL)->map(fn ($r) => ['value' => $r, 'label' => Roles::label($r)])->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $me = $request->user('admin');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(Roles::ALL)],
        ]);

        if ($data['role'] === Roles::OWNER && $me->role !== Roles::OWNER) {
            return $this->forbidden('Seul un propriétaire peut inviter un autre propriétaire.');
        }

        $emailTaken = CurrentCabinet::withoutScope(fn () => Admin::acrossCabinets()->where('email', $data['email'])->exists());
        if ($emailTaken) {
            return response()->json([
                'success' => false,
                'message' => 'Ce courriel est déjà utilisé par un compte de la plateforme.',
                'errors' => ['email' => ['Ce courriel est déjà utilisé.']],
            ], 422);
        }

        $member = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => null,
            'role' => $data['role'],
            'is_active' => true,
        ]);

        $emailSent = $this->sendInvitation($member, $me);
        $this->syncSeats();

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? "Invitation envoyée à {$member->email}."
                : "Membre ajouté, mais l'invitation n'a pas pu être envoyée. Réessayez avec « Renvoyer l'invitation ».",
            'email_sent' => $emailSent,
            'data' => $this->format($member, $me),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $me = $request->user('admin');
        $member = Admin::findOrFail($id);

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(Roles::ALL)],
            'is_active' => 'sometimes|boolean',
            'name' => 'sometimes|string|max:255',
        ]);

        if ($member->id === $me->id && (isset($data['role']) || array_key_exists('is_active', $data))) {
            return $this->forbidden('Vous ne pouvez pas modifier votre propre rôle ni désactiver votre compte.');
        }

        $touchesOwner = $member->role === Roles::OWNER || ($data['role'] ?? null) === Roles::OWNER;
        if ($touchesOwner && $me->role !== Roles::OWNER) {
            return $this->forbidden('Seul un propriétaire peut modifier un propriétaire ou en nommer un.');
        }

        $losesOwnerStatus = $member->role === Roles::OWNER
            && ((isset($data['role']) && $data['role'] !== Roles::OWNER) || (array_key_exists('is_active', $data) && ! $data['is_active']));
        if ($losesOwnerStatus) {
            $otherActiveOwners = Admin::where('role', Roles::OWNER)->where('is_active', true)->whereKeyNot($member->id)->count();
            if ($otherActiveOwners === 0) {
                return $this->forbidden('Le cabinet doit garder au moins un propriétaire actif.');
            }
        }

        $member->fill($data)->save();
        if (array_key_exists('is_active', $data)) {
            $this->syncSeats();
        }

        // Désactivation : on coupe aussi les sessions en cours.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $member->tokens()->delete();
        }

        return response()->json(['success' => true, 'data' => $this->format($member->fresh(), $me)]);
    }

    public function resendInvitation(Request $request, int $id)
    {
        $member = Admin::findOrFail($id);

        if ($member->password) {
            return response()->json([
                'success' => false,
                'message' => 'Ce membre a déjà activé son compte. Il peut utiliser « Mot de passe oublié ».',
            ], 422);
        }

        $sent = $this->sendInvitation($member, $request->user('admin'));

        return response()->json([
            'success' => $sent,
            'message' => $sent ? "Invitation renvoyée à {$member->email}." : "L'invitation n'a pas pu être envoyée.",
        ], $sent ? 200 : 500);
    }

    private function sendInvitation(Admin $member, Admin $invitedBy): bool
    {
        $token = AdminAccessTokens::issue($member, AdminAccessTokens::INVITE);
        $cabinet = Cabinet::find($member->cabinet_id);

        try {
            Mail::to($member->email)->send(new TeamInvitationMail($member, $cabinet, $invitedBy, $token));

            return true;
        } catch (\Throwable $e) {
            Log::error('Erreur envoi invitation équipe', ['admin_id' => $member->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function format(Admin $a, ?Admin $viewer): array
    {
        $pending = ! $a->password;

        return [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'role' => $a->role,
            'role_label' => Roles::label($a->role),
            'is_active' => (bool) $a->is_active,
            'status' => ! $a->is_active ? 'inactive' : ($pending ? 'invited' : 'active'),
            'invitation_expires_at' => $pending && $a->access_token_purpose === AdminAccessTokens::INVITE
                ? $a->access_token_expires_at?->toIso8601String() : null,
            'last_login_at' => $a->last_login_at?->toIso8601String(),
            'two_factor' => $a->hasTwoFactor(),
            'is_me' => $viewer && $viewer->id === $a->id,
        ];
    }

    /** Sièges facturés = comptes actifs : l'abonnement Stripe suit l'équipe. */
    private function syncSeats(): void
    {
        $cabinet = Cabinet::find(CurrentCabinet::require());
        if ($cabinet) {
            app(\App\Services\BillingService::class)->syncSeats($cabinet);
        }
    }

    private function forbidden(string $message)
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    // ─── Menus ouverts aux membres (S21) ─────────────────────────────────────

    public function moduleAccess()
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $roles = (array) ($cabinet->module_access ?? []);

        return response()->json(['success' => true, 'data' => [
            'modules' => Modules::catalog(),
            'roles' => collect(Modules::CONFIGURABLE_ROLES)->map(fn ($r) => [
                'value' => $r,
                'label' => Roles::label($r),
                'modules' => array_merge(array_fill_keys(array_keys(Modules::ALL), true), Modules::clean($roles[$r] ?? [])),
            ])->values(),
            'members' => Admin::whereIn('role', Modules::CONFIGURABLE_ROLES)->orderBy('name')->get()->map(fn (Admin $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'role' => $a->role,
                'role_label' => Roles::label($a->role),
                'overrides' => Modules::clean($a->module_access ?? []),
                'effective' => Modules::effective($a, $cabinet),
            ])->values(),
        ]]);
    }

    /** Réglage par rôle : { roles: { advisor: { invoices: false, ... }, assistant: {...} } } */
    public function updateRoleModules(Request $request)
    {
        $data = $request->validate(['roles' => 'required|array']);
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $clean = [];
        foreach (Modules::CONFIGURABLE_ROLES as $role) {
            // Ne garde que ce qui est fermé : « ouvert » est la valeur par défaut.
            $clean[$role] = array_filter(Modules::clean($data['roles'][$role] ?? []), fn ($v) => $v === false);
        }
        $cabinet->forceFill(['module_access' => $clean])->save();
        \App\Support\Audit::log('module_access_updated', 'cabinet', $cabinet->id, ['roles' => $clean]);

        return response()->json(['success' => true, 'message' => 'Menus des rôles enregistrés.']);
    }

    /** Réglage d'un membre : { overrides: { invoices: true } } ; null ou {} = suivre son rôle. */
    public function updateMemberModules(Request $request, int $id)
    {
        $data = $request->validate(['overrides' => 'nullable|array']);
        $member = Admin::findOrFail($id);
        if (! in_array($member->role, Modules::CONFIGURABLE_ROLES, true)) {
            return $this->forbidden('Les propriétaires et administrateurs voient toujours tous les menus.');
        }
        $overrides = Modules::clean($data['overrides'] ?? []);
        $member->forceFill(['module_access' => $overrides ?: null])->save();
        \App\Support\Audit::log('module_access_updated', 'admin', $member->id, ['overrides' => $overrides]);

        return response()->json(['success' => true, 'message' => "Menus de {$member->name} enregistrés.", 'data' => ['effective' => Modules::effective($member)]]);
    }

}
