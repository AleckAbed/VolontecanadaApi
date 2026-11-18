<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Dossier;
use App\Support\Tenancy\CurrentCabinet;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/** 2.6 journal d'activité, 2.7 double authentification du personnel. */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret#2026x';

    private Cabinet $cabinet;
    private Admin $owner;
    private Admin $advisor;
    private Client $client;
    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-11-24 15:00:00');
        $this->cabinet = Cabinet::firstOrFail();
        CurrentCabinet::runAs($this->cabinet->id, function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => self::PASSWORD, 'role' => 'owner', 'is_active' => true]);
            $this->advisor = Admin::create(['name' => 'Conseil', 'email' => 'conseil@example.test', 'password' => self::PASSWORD, 'role' => 'advisor', 'is_active' => true]);
            $this->client = Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test']);
            $this->dossier = Dossier::create(['client_id' => $this->client->id, 'scope' => 'client', 'name' => 'Résidence permanente']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function logs(): \Illuminate\Support\Collection
    {
        return AuditLog::acrossCabinets()->orderBy('id')->get();
    }

    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
    }

    // ─── Journal d'activité ─────────────────────────────────────────────────

    public function test_consultations_et_modifications_sont_journalisees_sans_donnees_du_client(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');

        $this->getJson("/api/admin/dossiers/{$this->dossier->id}")->assertOk();
        $this->getJson("/api/admin/dossiers/{$this->dossier->id}")->assertOk(); // même personne, < 10 min : une seule entrée
        $this->getJson("/api/admin/module-clients/{$this->client->id}")->assertOk();
        $this->putJson("/api/admin/dossiers/{$this->dossier->id}", ['name' => 'RP — Entrée express', 'status' => 'soumis'])->assertOk();

        $viewed = $this->logs()->where('action', 'viewed');
        $this->assertCount(2, $viewed);
        $v = $viewed->firstWhere('subject_type', 'dossier');
        $this->assertSame([$this->owner->id, $this->dossier->id, $this->client->id], [$v->actor_id, $v->dossier_id, $v->client_id]);

        $updated = $this->logs()->firstWhere('action', 'updated');
        $this->assertSame('dossier', $updated->subject_type);
        $this->assertContains('name', $updated->fields);
        $this->assertContains('status', $updated->fields);

        // Ni nom de client ni valeur de champ dans le journal.
        $raw = $this->logs()->toJson();
        $this->assertStringNotContainsString('Tremblay', $raw);
        $this->assertStringNotContainsString('Entrée express', $raw);

        // Lecture réservée aux gestionnaires, avec les noms résolus à l'affichage.
        $this->getJson("/api/admin/audit-logs?dossier_id={$this->dossier->id}")->assertOk()
            ->assertJsonPath('data.items.0.dossier.name', 'RP — Entrée express')
            ->assertJsonPath('data.items.0.client.name', 'Marie Tremblay');
        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/audit-logs')->assertForbidden();
    }

    public function test_le_journal_ne_se_modifie_pas(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->getJson("/api/admin/dossiers/{$this->dossier->id}")->assertOk();
        $log = $this->logs()->first();

        $this->expectException(LogicException::class);
        CurrentCabinet::runAs($this->cabinet->id, fn () => $log->update(['action' => 'deleted']));
    }

    public function test_connexions_reussies_et_echouees(): void
    {
        $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => 'mauvais'])->assertStatus(422);
        $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $this->assertSame(['login_failed', 'login'], $this->logs()->whereIn('action', ['login', 'login_failed'])->pluck('action')->values()->all());
        $this->assertSame($this->cabinet->id, $this->logs()->last()->cabinet_id);
    }

    // ─── Double authentification ────────────────────────────────────────────

    /** Active la 2FA par application pour $admin et renvoie [secret, codes de secours]. */
    private function enableTotp(Admin $admin): array
    {
        Sanctum::actingAs($admin, ['*'], 'admin');
        $this->postJson('/api/admin/two-factor/setup', ['method' => 'totp', 'password' => 'faux'])->assertStatus(422);
        $setup = $this->postJson('/api/admin/two-factor/setup', ['method' => 'totp', 'password' => self::PASSWORD])->assertOk()->json('data');
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);

        $this->postJson('/api/admin/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $code = TwoFactor::totp($setup['secret'], intdiv(now()->getTimestamp(), 30));
        $codes = $this->postJson('/api/admin/two-factor/confirm', ['code' => $code])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($admin->fresh()->hasTwoFactor());
        $this->assertNotSame($setup['secret'], $admin->fresh()->getRawOriginal('two_factor_secret')); // chiffré en base

        return [$setup['secret'], $codes];
    }

    public function test_connexion_avec_application_d_authentification(): void
    {
        [$secret, $codes] = $this->enableTotp($this->owner);
        $this->freshRequest();

        // Le mot de passe seul n'ouvre plus de session.
        $res = $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertOk();
        $res->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.token');
        $challenge = $res->json('data.challenge');

        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);
        Carbon::setTestNow(now()->addSeconds(30)); // code suivant (l'activation a consommé le précédent)
        $code = TwoFactor::totp($secret, intdiv(now()->getTimestamp(), 30));
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => $code])->assertOk()->assertJsonStructure(['data' => ['token']]);

        // Le même code ne sert pas deux fois (rejeu), même avec un nouveau défi.
        $challenge2 = $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->json('data.challenge');
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge2, 'code' => $code])->assertStatus(422);

        // Code de secours : une seule fois.
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge2, 'recovery_code' => $codes[0]])->assertOk()
            ->assertJsonPath('data.recovery_codes_left', 7);
        $challenge3 = $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->json('data.challenge');
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge3, 'recovery_code' => $codes[0]])->assertStatus(422);

        $actions = $this->logs()->pluck('action');
        $this->assertContains('two_factor_enabled', $actions);
        $this->assertContains('two_factor_failed', $actions);
        $this->assertContains('recovery_code_used', $actions);
    }

    public function test_cinq_erreurs_annulent_le_defi(): void
    {
        $this->enableTotp($this->owner);
        $this->freshRequest();
        $challenge = $this->postJson('/api/admin/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD])->json('data.challenge');
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(429);
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'challenge_expired');
    }

    public function test_code_par_courriel(): void
    {
        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->postJson('/api/admin/two-factor/setup', ['method' => 'email', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.email_hint', 'c•••@example.test');
        $code = null;
        Mail::assertSent(TwoFactorCodeMail::class, function ($m) use (&$code) { $code = $m->code; return $m->hasTo('conseil@example.test'); });
        $this->postJson('/api/admin/two-factor/confirm', ['code' => $code])->assertOk();

        $this->freshRequest();
        $challenge = $this->postJson('/api/admin/login', ['email' => 'conseil@example.test', 'password' => self::PASSWORD])
            ->assertJsonPath('data.method', 'email')->json('data.challenge');
        $sent = Mail::sent(TwoFactorCodeMail::class)->last();
        $this->postJson('/api/admin/login/two-factor', ['challenge' => $challenge, 'code' => $sent->code])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_double_authentification_exigee_par_le_cabinet(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson('/api/admin/cabinet/security', ['require_two_factor' => true])->assertStatus(422); // le gestionnaire d'abord
        $this->enableTotp($this->owner);
        $this->putJson('/api/admin/cabinet/security', ['require_two_factor' => true])->assertOk();

        // Le conseiller sans 2FA ne peut que l'activer.
        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/dossiers')->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.two_factor.required', true);
        $this->getJson('/api/admin/two-factor')->assertOk()->assertJsonPath('data.required_by_cabinet', true);

        // Le propriétaire ne peut plus la désactiver, mais peut réinitialiser un membre.
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson('/api/admin/two-factor/disable', ['password' => self::PASSWORD])->assertStatus(422);
        $this->enableTotpFor($this->advisor);
        $this->advisor->createToken('t');
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson("/api/admin/team/{$this->advisor->id}/reset-two-factor")->assertOk();
        $this->assertFalse($this->advisor->fresh()->hasTwoFactor());
        $this->assertSame(0, $this->advisor->tokens()->count());
        $this->assertTrue($this->logs()->contains(fn ($l) => $l->action === 'two_factor_reset' && $l->subject_id === $this->advisor->id && $l->actor_id === $this->owner->id));
    }

    private function enableTotpFor(Admin $admin): void
    {
        $admin->forceFill(['two_factor_method' => 'totp', 'two_factor_secret' => TwoFactor::generateSecret(), 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
    }
}
