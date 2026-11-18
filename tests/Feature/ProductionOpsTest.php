<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\Cabinet;
use App\Models\Collaborator;
use App\Models\PlatformAdmin;
use App\Support\SentryScrubber;
use App\Support\Tenancy\CurrentCabinet;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Console plateforme et collaborateurs en double authentification, santé, suivi des erreurs. */
class ProductionOpsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret#2026xyz';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-11-25 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    public function test_console_plateforme_exige_la_double_authentification_en_production(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Éditeur', 'email' => 'editeur@example.test', 'password' => self::PASSWORD]);

        // Hors production : connexion possible, mais signalée.
        $this->postJson('/api/platform/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.two_factor_missing', true);

        // En production : refusée sans double authentification.
        $this->app['env'] = 'production';
        $this->postJson('/api/platform/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertForbidden();

        // Activée (comme le fait php artisan platform:two-factor) : défi puis code.
        $secret = TwoFactor::generateSecret();
        [$plain, $hashes] = TwoFactor::generateRecoveryCodes();
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

        $challenge = $this->postJson('/api/platform/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.two_factor_required', true)->json('data.challenge');
        $this->postJson('/api/platform/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $code = TwoFactor::totp($secret, intdiv(now()->getTimestamp(), 30));
        $this->postJson('/api/platform/login/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('data.admin.two_factor', true)->assertCookie('platform_token');

        // Code de secours.
        $challenge = $this->postJson('/api/platform/login', ['email' => $admin->email, 'password' => self::PASSWORD])->json('data.challenge');
        $this->postJson('/api/platform/login/two-factor', ['challenge' => $challenge, 'recovery_code' => $plain[0]])->assertOk();
    }

    public function test_code_par_courriel_pour_les_collaborateurs_si_le_cabinet_l_exige(): void
    {
        $cabinet = Cabinet::firstOrFail();
        $collab = CurrentCabinet::runAs($cabinet->id, fn () => Collaborator::create([
            'first_name' => 'Léa', 'last_name' => 'Traductrice', 'email' => 'lea@example.test', 'password' => self::PASSWORD, 'is_active' => true,
        ]));
        CurrentCabinet::clear();

        // Sans exigence : connexion directe.
        $this->postJson('/api/collaborator/login', ['email' => 'lea@example.test', 'password' => self::PASSWORD])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $cabinet->forceFill(['require_two_factor_collaborators' => true])->save();
        $res = $this->postJson('/api/collaborator/login', ['email' => 'lea@example.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.token');
        $challenge = $res->json('data.challenge');
        $sent = Mail::sent(TwoFactorCodeMail::class)->last();
        $this->assertTrue($sent->hasTo('lea@example.test'));

        $this->postJson('/api/collaborator/login/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/collaborator/login/two-factor', ['challenge' => $challenge, 'code' => $sent->code])
            ->assertOk()->assertJsonPath('data.collaborator.id', $collab->id)->assertJsonStructure(['data' => ['token']]);
    }

    public function test_sante_publique_et_detail_avec_jeton(): void
    {
        config(['app.health_token' => 'jeton-surveillance', 'queue.default' => 'database']);

        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/api/health', ['X-Health-Token' => 'mauvais'])->assertOk()->assertJsonMissingPath('checks');
        $this->getJson('/api/health', ['X-Health-Token' => 'jeton-surveillance'])->assertOk()
            ->assertJsonPath('checks.database.ok', true)->assertJsonPath('checks.queue.pending', 0);

        // Un courriel bloqué depuis 30 minutes : le worker est arrêté.
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);
        $this->getJson('/api/health', ['X-Health-Token' => 'jeton-surveillance'])->assertStatus(503)
            ->assertJsonPath('status', 'degraded')->assertJsonPath('checks.queue.ok', false);
        DB::table('jobs')->delete();

        // En production, un planificateur muet est une panne.
        $this->app['env'] = 'production';
        $this->getJson('/api/health')->assertStatus(503);
        Cache::put('health:scheduler', now()->getTimestamp(), 3600);
        config(['backup.encryption_key' => null]);
        $this->getJson('/api/health', ['X-Health-Token' => 'jeton-surveillance'])
            ->assertJsonPath('checks.scheduler.ok', true)->assertJsonPath('checks.backups.ok', false);
    }

    public function test_les_rapports_d_erreur_ne_contiennent_pas_de_donnees_personnelles(): void
    {
        $this->assertSame(
            'Échec pour [courriel], passeport [numéro], tél. [numéro]',
            SentryScrubber::scrub('Échec pour marie@example.test, passeport AB1234567, tél. 5145550000')
        );

        $event = \Sentry\Event::createEvent();
        $event->setRequest(['url' => 'https://api.example.ca/api/admin/dossiers/4?token=secret', 'method' => 'GET', 'headers' => ['Authorization' => 'Bearer x'], 'data' => ['passport' => 'AB1234567']]);
        $event->setUser(\Sentry\UserDataBag::createFromUserIdentifier('42'));
        $clean = SentryScrubber::beforeSend($event);
        $this->assertSame(['url' => 'https://api.example.ca/api/admin/dossiers/4', 'method' => 'GET'], $clean->getRequest());
        $this->assertNull($clean->getUser());
    }
}
