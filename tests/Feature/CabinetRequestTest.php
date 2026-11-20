<?php

namespace Tests\Feature;

use App\Mail\CabinetRequestMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\CabinetRequest;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S21 — Ouverture d'un cabinet sur demande : formulaire public, étude dans la console
 * plateforme, création du cabinet et invitation du propriétaire.
 */
class CabinetRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Motdepasse#2026';

    private PlatformAdmin $platform;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->platform = PlatformAdmin::create(['name' => 'Éditeur', 'email' => 'editeur@example.test', 'password' => 'Plateforme#2026!']);
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'cabinet_name' => 'Immigration Laval', 'province' => 'QC', 'name' => 'Marie Tremblay',
            'email' => 'Marie@Example.test', 'phone' => '+1 514 555 0123', 'team_size' => '2-5',
            'message' => 'Nous avons 40 dossiers actifs.', 'accept_terms' => true,
        ];
    }

    public function test_l_inscription_libre_est_fermee_par_defaut(): void
    {
        $this->postJson('/api/cabinets/register', [
            'cabinet_name' => 'X', 'name' => 'Y', 'email' => 'y@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'accept_terms' => true,
        ])->assertForbidden()->assertJsonPath('code', 'self_signup_disabled');
    }

    public function test_demande_enregistree_accuse_de_reception_et_avis_plateforme(): void
    {
        $this->postJson('/api/cabinet-requests', $this->payload())->assertStatus(202);

        $req = CabinetRequest::firstOrFail();
        $this->assertSame('marie@example.test', $req->email);
        $this->assertSame('pending', $req->status);
        $this->assertNotNull($req->consent_at);
        Mail::assertQueued(CabinetRequestMail::class, fn ($m) => $m->kind === 'received' && $m->hasTo('marie@example.test'));
        Mail::assertQueued(CabinetRequestMail::class, fn ($m) => $m->kind === 'new' && $m->hasTo('editeur@example.test'));

        // Un doublon en attente n'en crée pas un second.
        $this->postJson('/api/cabinet-requests', $this->payload())->assertStatus(202);
        $this->assertSame(1, CabinetRequest::count());
    }

    public function test_consentement_obligatoire_et_robots_refuses(): void
    {
        $this->postJson('/api/cabinet-requests', $this->payload(['accept_terms' => false]))->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->postJson('/api/cabinet-requests', $this->payload(['website' => 'http://spam.test']))->assertStatus(422);
        $this->assertSame(0, CabinetRequest::count());
    }

    public function test_acceptation_cree_le_cabinet_et_le_proprietaire_active_son_compte(): void
    {
        $this->postJson('/api/cabinet-requests', $this->payload())->assertStatus(202);
        $req = CabinetRequest::firstOrFail();

        // Réservé à la console plateforme.
        $this->getJson('/api/platform/cabinet-requests')->assertUnauthorized();

        Sanctum::actingAs($this->platform, ['*'], 'platform');
        $this->getJson('/api/platform/cabinet-requests')->assertOk()->assertJsonPath('meta.pending', 1)->assertJsonCount(1, 'data');
        $this->postJson("/api/platform/cabinet-requests/{$req->id}/approve")->assertOk();
        $this->postJson("/api/platform/cabinet-requests/{$req->id}/approve")->assertStatus(422);

        $req->refresh();
        $this->assertSame('approved', $req->status);
        $cabinet = Cabinet::findOrFail($req->cabinet_id);
        $this->assertSame('Immigration Laval', $cabinet->name);
        $owner = Admin::acrossCabinets()->where('email', 'marie@example.test')->firstOrFail();
        $this->assertSame('owner', $owner->role);
        $this->assertNull($owner->password);

        $token = null;
        Mail::assertQueued(CabinetRequestMail::class, function ($m) use (&$token) {
            if ($m->kind === 'approved') {
                $token = $m->token;
            }

            return $m->kind === 'approved';
        });

        // Le propriétaire active son compte ; la 2FA est exigée d'emblée pour un nouveau cabinet.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/access/check', ['token' => $token])->assertOk()
            ->assertJsonPath('data.purpose', 'invite')->assertJsonPath('data.cabinet_name', 'Immigration Laval');
    }

    public function test_refus_avec_motif(): void
    {
        $this->postJson('/api/cabinet-requests', $this->payload())->assertStatus(202);
        $req = CabinetRequest::firstOrFail();

        Sanctum::actingAs($this->platform, ['*'], 'platform');
        $this->postJson("/api/platform/cabinet-requests/{$req->id}/decline", ['reason' => 'Numéro CICC introuvable.'])->assertOk();
        $this->assertSame('declined', $req->fresh()->status);
        Mail::assertQueued(CabinetRequestMail::class, fn ($m) => $m->kind === 'declined');
        $this->assertSame(1, Cabinet::count());
    }
}
