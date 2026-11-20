<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Mail\TwoFactorCodeMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\Tenancy\CurrentCabinet;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S21 — Activation d'un compte du personnel quand le cabinet exige la double
 * authentification : elle se règle sur la page d'activation, puis la session s'ouvre
 * directement (avant, la personne tombait sur un chargement sans fin).
 */
class ActivationTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Motdepasse#2026';

    private Cabinet $cabinet;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cabinet = Cabinet::firstOrFail();
        $this->owner = CurrentCabinet::runAs($this->cabinet->id, fn () => Admin::create([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => self::PASSWORD, 'role' => 'owner', 'is_active' => true,
        ]));
    }

    private function invite(string $email): string
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson('/api/admin/team', ['name' => 'Nouveau', 'email' => $email, 'role' => 'assistant'])->assertCreated();
        $token = null;
        Mail::assertQueued(TeamInvitationMail::class, function (TeamInvitationMail $m) use ($email, &$token) {
            if ($m->member->email === $email) {
                $token = $m->token;
            }

            return true;
        });
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_cabinet_exigeant_la_2fa_application_puis_session_ouverte(): void
    {
        $token = $this->invite('totp@example.test');
        $this->cabinet->forceFill(['require_two_factor' => true])->save();

        $this->postJson('/api/admin/access/check', ['token' => $token])
            ->assertOk()->assertJsonPath('data.two_factor_required', true)->assertJsonPath('data.has_two_factor', false);

        // Sans 2FA, l'activation est refusée et rien n'est enregistré.
        $this->postJson('/api/admin/access/complete', ['token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('code', 'two_factor_setup_required');
        $this->assertNull(Admin::acrossCabinets()->where('email', 'totp@example.test')->value('password'));

        $setup = $this->postJson('/api/admin/access/two-factor/setup', ['token' => $token, 'method' => 'totp'])->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_uri'));

        // Mauvais code : refusé.
        $this->postJson('/api/admin/access/complete', [
            'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'two_factor_code' => '000000',
        ])->assertStatus(422);

        $res = $this->postJson('/api/admin/access/complete', [
            'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'two_factor_code' => TwoFactor::totp($secret, intdiv(time(), 30)),
        ])->assertOk()->assertJsonPath('data.logged_in', true);

        $this->assertCount(8, $res->json('data.recovery_codes'));
        $this->assertNotEmpty($res->json('data.token'));
        $admin = Admin::acrossCabinets()->where('email', 'totp@example.test')->first();
        $this->assertTrue($admin->hasTwoFactor());
        $this->assertSame('totp', $admin->two_factor_method);

        // La session ouverte donne accès à l'espace, sans blocage « 2FA requise ».
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('data.token'))->getJson('/api/admin/me')->assertOk();
    }

    public function test_cabinet_exigeant_la_2fa_par_courriel(): void
    {
        $token = $this->invite('mail@example.test');
        $this->cabinet->forceFill(['require_two_factor' => true])->save();

        $this->postJson('/api/admin/access/two-factor/setup', ['token' => $token, 'method' => 'email'])
            ->assertOk()->assertJsonPath('data.method', 'email');
        $code = null;
        Mail::assertSent(TwoFactorCodeMail::class, function (TwoFactorCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        $this->postJson('/api/admin/access/complete', [
            'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'two_factor_code' => $code,
        ])->assertOk()->assertJsonPath('data.logged_in', true);
        $this->assertSame('email', Admin::acrossCabinets()->where('email', 'mail@example.test')->value('two_factor_method'));
    }

    public function test_sans_exigence_la_session_s_ouvre_directement(): void
    {
        $this->cabinet->forceFill(['require_two_factor' => false])->save();
        $token = $this->invite('simple@example.test');

        $this->postJson('/api/admin/access/check', ['token' => $token])->assertJsonPath('data.two_factor_required', false);
        $this->postJson('/api/admin/access/complete', ['token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.logged_in', true)->assertJsonPath('data.recovery_codes', null);
    }

    public function test_reinitialisation_avec_2fa_existante_ne_connecte_pas_directement(): void
    {
        $this->owner->forceFill([
            'two_factor_method' => 'totp', 'two_factor_secret' => TwoFactor::generateSecret(),
            'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();
        $token = \App\Support\AdminAccessTokens::issue($this->owner, \App\Support\AdminAccessTokens::RESET);

        $this->postJson('/api/admin/access/complete', ['token' => $token, 'password' => 'Nouveau#Motdepasse26', 'password_confirmation' => 'Nouveau#Motdepasse26'])
            ->assertOk()->assertJsonPath('data.logged_in', false)->assertJsonMissingPath('data.token');
    }
}
