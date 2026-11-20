<?php

namespace Tests\Feature;

use App\Mail\ClientPortalAccessMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Client;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S21 — Retours de recette sur la communication avec le client :
 *  - le cabinet ne choisit plus le mot de passe du client ;
 *  - langue de communication du client (fr, en, es) pour tous ses courriels ;
 *  - « Inviter » ne renvoie plus de lien de mot de passe à un client déjà inscrit ;
 *  - état du portail sur la fiche.
 */
class ClientCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cabinet = Cabinet::firstOrFail();
        $this->cabinet->forceFill(['require_two_factor' => false])->save();
        $this->owner = CurrentCabinet::runAs($this->cabinet->id, fn () => Admin::create([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Motdepasse#2026', 'role' => 'owner', 'is_active' => true,
        ]));
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    public function test_creation_sans_mot_de_passe_avec_langue(): void
    {
        $res = $this->postJson('/api/admin/module-clients', [
            'client_type' => 'single', 'first_name' => 'Amina', 'last_name' => 'Diallo', 'email' => 'amina@example.test',
            'preferred_locale' => 'en', 'password' => 'Imposé#2026', 'phone' => '+221 77 123 45 67',
        ])->assertSuccessful();

        $client = Client::acrossCabinets()->where('email', 'amina@example.test')->firstOrFail();
        $this->assertNull($client->password, 'Le cabinet ne doit pas pouvoir fixer le mot de passe du client.');
        $this->assertSame('en', $client->preferred_locale);
        $this->assertSame('none', $client->portal_status);

        $this->postJson('/api/admin/module-clients', [
            'client_type' => 'single', 'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.test', 'preferred_locale' => 'de',
        ])->assertStatus(422);
    }

    public function test_invitation_puis_simple_avis_quand_le_client_est_inscrit(): void
    {
        Mail::fake();
        $client = CurrentCabinet::runAs($this->cabinet->id, fn () => Client::create(['first_name' => 'Amina', 'last_name' => 'Diallo', 'email' => 'amina@example.test']));

        $this->postJson("/api/admin/module-clients/{$client->id}/portal-invite")->assertOk()->assertJsonPath('data.portal_status', 'invited');
        Mail::assertQueued(ClientPortalAccessMail::class, fn ($m) => $m->purpose === 'invite' && $m->token !== '');

        // Le client a créé son espace : « Inviter » devient un simple avis, sans lien de mot de passe.
        $client->forceFill(['password' => 'Portail#2026x', 'access_token_hash' => null, 'access_token_expires_at' => null])->save();
        $this->postJson("/api/admin/module-clients/{$client->id}/portal-invite")->assertOk()->assertJsonPath('data.portal_status', 'active');
        Mail::assertQueued(ClientPortalAccessMail::class, fn ($m) => $m->purpose === ClientPortalAccessMail::REMINDER && $m->token === '');
    }

    public function test_courriels_dans_la_langue_du_client(): void
    {
        config(['mail.default' => 'array']);
        $cabinet = $this->cabinet;
        $before = app()->getLocale();

        foreach (['fr' => 'vous invite à créer votre espace client', 'en' => 'invites you to create your client portal', 'es' => 'le invita a crear su espacio de cliente'] as $locale => $expected) {
            $client = CurrentCabinet::runAs($cabinet->id, fn () => Client::create([
                'first_name' => 'Amina', 'last_name' => $locale, 'email' => "amina-{$locale}@example.test", 'preferred_locale' => $locale,
            ]));
            Mail::to($client->email)->send(new ClientPortalAccessMail($client, $cabinet, 'jeton', 'invite'));

            $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last();
            $subject = $sent->getOriginalMessage()->getSubject();
            $html = $sent->getOriginalMessage()->getHtmlBody();
            $this->assertStringContainsStringIgnoringCase($expected, $subject . ' ' . strip_tags(html_entity_decode($html)), "Langue {$locale}");
            $this->assertStringContainsString("lang=\"{$locale}\"", $html);
        }
        // La langue de l'application n'est pas modifiée par l'envoi.
        $this->assertSame($before, app()->getLocale());
    }
}
