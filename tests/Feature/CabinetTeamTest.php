<?php

namespace Tests\Feature;

use App\Mail\AdminPasswordResetMail;
use App\Mail\TeamInvitationMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Category;
use App\Models\Client;
use App\Models\Dossier;
use App\Models\FormType;
use App\Models\ImmigrationService;
use App\Models\PlatformAdmin;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S3 — Inscription d'un cabinet, rôles, équipe, liens d'accès, console plateforme.
 */
class CabinetTeamTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Motdepasse#2026';

    private Cabinet $cabinet;
    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->cabinet = Cabinet::firstOrFail();
        $this->owner = $this->makeAdmin('owner', 'owner@example.test');
        // Ces tests couvrent l'inscription libre (désactivée par défaut, voir CabinetRequestTest).
        config(['security.cabinet_self_signup' => true]);
    }

    private function makeAdmin(string $role, string $email, ?Cabinet $cabinet = null): Admin
    {
        return CurrentCabinet::runAs(($cabinet ?? $this->cabinet)->id, fn () => Admin::create([
            'name' => ucfirst($role), 'email' => $email, 'password' => self::STRONG_PASSWORD, 'role' => $role, 'is_active' => true,
        ]));
    }

    private function inviteTokenFor(string $email): string
    {
        $token = null;
        Mail::assertQueued(TeamInvitationMail::class, function (TeamInvitationMail $mail) use ($email, &$token) {
            if ($mail->member->email === $email) {
                $token = $mail->token;
            }

            return true;
        });
        $this->assertNotNull($token, "Aucune invitation envoyée à {$email}");

        return $token;
    }

    // ── Inscription d'un cabinet ────────────────────────────────────────────

    public function test_inscription_cree_le_cabinet_son_proprietaire_et_un_catalogue_de_depart(): void
    {
        $response = $this->postJson('/api/cabinets/register', [
            'cabinet_name' => 'Immigration Laval',
            'province' => 'QC',
            'name' => 'Marie Laval',
            'email' => 'marie@laval.test',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'accept_terms' => true,
        ])->assertCreated();

        $cabinet = Cabinet::where('name', 'Immigration Laval')->firstOrFail();
        $this->assertSame('immigration-laval', $cabinet->slug);

        $me = $this->withToken($response->json('data.token'))->getJson('/api/admin/me')->assertOk();
        $me->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.cabinet.name', 'Immigration Laval')
            ->assertJsonPath('data.permissions.manage_team', true);

        CurrentCabinet::runAs($cabinet->id, function () {
            $this->assertGreaterThan(0, Category::count());
            $this->assertGreaterThan(0, FormType::count());
            $this->assertGreaterThan(0, ImmigrationService::count());
        });
        // Le catalogue du nouveau cabinet n'a rien ajouté au cabinet existant.
        CurrentCabinet::runAs($this->cabinet->id, fn () => $this->assertSame(0, Category::count()));
    }

    public function test_inscription_refuse_un_courriel_deja_utilise_et_un_mot_de_passe_faible(): void
    {
        $base = [
            'cabinet_name' => 'Autre cabinet', 'name' => 'X', 'accept_terms' => true,
        ];

        $this->postJson('/api/cabinets/register', $base + [
            'email' => 'owner@example.test', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/cabinets/register', $base + [
            'email' => 'neuf@example.test', 'password' => 'faible', 'password_confirmation' => 'faible',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(1, Cabinet::count());
    }

    // ── Rôles ───────────────────────────────────────────────────────────────

    public function test_un_conseiller_ne_peut_ni_configurer_ni_gerer_l_equipe(): void
    {
        Sanctum::actingAs($this->makeAdmin('advisor', 'conseiller@example.test'), ['*'], 'admin');

        $this->postJson('/api/admin/categories', ['name' => 'X', 'type' => 'form'])->assertForbidden();
        $this->getJson('/api/admin/team')->assertForbidden();
        $this->getJson('/api/admin/categories')->assertOk(); // la lecture reste permise
    }

    public function test_un_adjoint_ne_peut_pas_supprimer_mais_un_conseiller_oui(): void
    {
        $dossierId = CurrentCabinet::runAs($this->cabinet->id, function () {
            $client = Client::create(['first_name' => 'C', 'last_name' => 'D', 'email' => 'c@example.test', 'password' => 'secret-123']);

            return Dossier::create(['client_id' => $client->id, 'scope' => 'client', 'name' => 'D'])->id;
        });

        Sanctum::actingAs($this->makeAdmin('assistant', 'adjoint@example.test'), ['*'], 'admin');
        $this->deleteJson("/api/admin/dossiers/{$dossierId}")->assertForbidden();

        Sanctum::actingAs($this->makeAdmin('advisor', 'conseiller@example.test'), ['*'], 'admin');
        $this->deleteJson("/api/admin/dossiers/{$dossierId}")->assertSuccessful();
    }

    // ── Équipe et invitations ───────────────────────────────────────────────

    public function test_invitation_d_un_membre_puis_activation_et_connexion(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');

        $this->postJson('/api/admin/team', ['name' => 'Nouvel Adjoint', 'email' => 'nouveau@example.test', 'role' => 'assistant'])
            ->assertCreated()->assertJsonPath('data.status', 'invited');

        // Sans mot de passe choisi, la connexion est impossible.
        $this->postJson('/api/admin/login', ['email' => 'nouveau@example.test', 'password' => 'nimporte'])->assertStatus(422);

        $token = $this->inviteTokenFor('nouveau@example.test');

        $this->postJson('/api/admin/access/check', ['token' => $token])
            ->assertOk()->assertJsonPath('data.purpose', 'invite')->assertJsonPath('data.email', 'nouveau@example.test');

        $this->postJson('/api/admin/access/complete', [
            'token' => $token, 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk();

        // Le lien ne sert qu'une fois.
        $this->postJson('/api/admin/access/check', ['token' => $token])->assertStatus(410);

        $this->postJson('/api/admin/login', ['email' => 'nouveau@example.test', 'password' => self::STRONG_PASSWORD])
            ->assertOk()->assertJsonPath('data.admin.role', 'assistant');
    }

    public function test_regles_de_l_equipe_sur_les_proprietaires(): void
    {
        $admin = $this->makeAdmin('admin', 'admin@example.test');

        // Un administrateur ne peut ni nommer un propriétaire ni modifier un propriétaire.
        Sanctum::actingAs($admin, ['*'], 'admin');
        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'owner'])->assertForbidden();
        $this->putJson("/api/admin/team/{$this->owner->id}", ['is_active' => false])->assertForbidden();

        // Personne ne modifie son propre rôle ; le dernier propriétaire reste propriétaire.
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson("/api/admin/team/{$this->owner->id}", ['role' => 'admin'])->assertForbidden();

        $secondOwner = $this->makeAdmin('owner', 'owner2@example.test');
        $this->putJson("/api/admin/team/{$secondOwner->id}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_desactiver_un_membre_ferme_ses_sessions(): void
    {
        $advisor = $this->makeAdmin('advisor', 'conseiller@example.test');
        $advisorToken = $advisor->createToken('t')->plainTextToken;

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson("/api/admin/team/{$advisor->id}", ['is_active' => false])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($advisorToken)->getJson('/api/admin/me')->assertUnauthorized();
    }

    // ── Mot de passe oublié ─────────────────────────────────────────────────

    public function test_mot_de_passe_oublie_ne_revele_pas_les_comptes_et_reinitialise(): void
    {
        $this->postJson('/api/admin/password/forgot', ['email' => 'inconnu@example.test'])->assertOk();
        Mail::assertNothingOutgoing();

        $oldSession = $this->owner->createToken('ancienne')->plainTextToken;

        $this->postJson('/api/admin/password/forgot', ['email' => 'owner@example.test'])->assertOk();
        $token = null;
        Mail::assertQueued(AdminPasswordResetMail::class, function (AdminPasswordResetMail $m) use (&$token) {
            $token = $m->token;

            return $m->purpose === 'reset';
        });

        $this->postJson('/api/admin/access/complete', [
            'token' => $token, 'password' => 'Nouveau#Mdp2026', 'password_confirmation' => 'Nouveau#Mdp2026',
        ])->assertOk();

        $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::STRONG_PASSWORD])->assertStatus(422);
        $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => 'Nouveau#Mdp2026'])->assertOk();

        // Les sessions ouvertes avec l'ancien mot de passe sont fermées.
        $this->app['auth']->forgetGuards();
        $this->withToken($oldSession)->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_un_lien_expire_est_refuse(): void
    {
        $token = \App\Support\AdminAccessTokens::issue($this->owner, 'reset');
        $this->travel(2)->hours();

        $this->postJson('/api/admin/access/check', ['token' => $token])->assertStatus(410);
    }

    // ── Console plateforme ──────────────────────────────────────────────────

    public function test_console_plateforme_liste_et_suspend_un_cabinet(): void
    {
        PlatformAdmin::create(['name' => 'Éditeur', 'email' => 'editeur@example.test', 'password' => 'Plateforme#2026!']);

        // Un admin de cabinet n'a pas accès à la console.
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->getJson('/api/platform/cabinets')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/platform/login', ['email' => 'editeur@example.test', 'password' => 'Plateforme#2026!'])
            ->assertOk()->assertCookie('platform_token');

        $platform = PlatformAdmin::firstOrFail();
        Sanctum::actingAs($platform, ['*'], 'platform');

        $this->getJson('/api/platform/cabinets')->assertOk()
            ->assertJsonPath('data.cabinets.0.name', $this->cabinet->name)
            ->assertJsonPath('data.cabinets.0.seats_used', 1)
            ->assertJsonPath('data.cabinets.0.owner.email', 'owner@example.test');

        $this->patchJson("/api/platform/cabinets/{$this->cabinet->id}", ['is_active' => false])->assertOk();

        // Cabinet suspendu : connexion refusée avec un message clair.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::STRONG_PASSWORD])
            ->assertStatus(422)->assertJsonFragment(['Ce cabinet est suspendu. Contactez le support de la plateforme.']);
    }
}
