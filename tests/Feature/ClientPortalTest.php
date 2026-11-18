<?php

namespace Tests\Feature;

use App\Mail\ClientPortalAccessMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\DocumentTemplate;
use App\Models\Dossier;
use App\Models\FamilyMember;
use App\Models\Invitation;
use App\Models\InvitationItem;
use App\Models\QuestionnaireRequest;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S4 — Profil client unique, préremplissage des PDF, portail client.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->cabinet = Cabinet::firstOrFail();
        CurrentCabinet::runAs($this->cabinet->id, function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->client = Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test', 'password' => null]);
        });
    }

    private function inCabinet(callable $fn): mixed
    {
        return CurrentCabinet::runAs($this->cabinet->id, $fn);
    }

    /** Modèle PDF relié : un champ XFA (clé DOM) et un champ AcroForm (stockage pdf.js). */
    private function mappedTemplate(): DocumentTemplate
    {
        return $this->inCabinet(function () {
            $t = DocumentTemplate::create(['name' => 'IMM 5257', 'pdf_path' => 'x.pdf', 'created_by' => $this->owner->id]);
            $t->field_map = ['FamilyName30877' => 'lastName', '__as__45R' => 'passportNumber', 'GivenName1' => 'firstName'];
            $t->save();

            return $t;
        });
    }

    public function test_le_questionnaire_general_alimente_le_profil(): void
    {
        $qr = $this->inCabinet(fn () => QuestionnaireRequest::create([
            'unique_code' => 'QRCODE123', 'email' => 'marie@example.test', 'client_id' => $this->client->id,
            'form_type' => 'questionnaire_demandeur_001', 'status' => 'pending', 'expires_at' => now()->addDays(5),
        ]));

        $this->postJson("/api/questionnaires/{$qr->unique_code}/save", [
            'form_data' => ['lastName' => 'Tremblay-Roy', 'passportNumber' => 'AB123456', 'champInconnu' => 'ignoré'],
        ])->assertOk();

        $data = $this->inCabinet(fn () => ClientProfile::where('client_id', $this->client->id)->value('data'));
        $data = is_string($data) ? json_decode($data, true) : $data;
        $this->assertSame('Tremblay-Roy', $data['lastName']);
        $this->assertSame('AB123456', $data['passportNumber']);
        $this->assertArrayNotHasKey('champInconnu', $data);
    }

    public function test_la_correspondance_se_construit_avec_des_jetons(): void
    {
        $template = $this->inCabinet(fn () => DocumentTemplate::create(['name' => 'IMM 0008', 'pdf_path' => 'y.pdf', 'created_by' => $this->owner->id]));
        Sanctum::actingAs($this->owner, ['*'], 'admin');

        $this->putJson("/api/admin/document-templates/{$template->id}/field-map", [
            'form_data' => [
                'FamilyName30877' => '@nom',
                '__as__45R' => ['value' => '@Passeport '], // casse et espaces tolérés
                'Remarques' => 'texte libre',
                'Autre' => '@inconnu',
            ],
        ])->assertOk()
            ->assertJsonPath('data.mapped_count', 2)
            ->assertJsonPath('data.unknown_tokens', ['@inconnu']);

        $this->assertSame(
            ['FamilyName30877' => 'lastName', '__as__45R' => 'passportNumber'],
            $template->fresh()->field_map
        );

        // Les jetons sont réaffichés pour montrer la correspondance actuelle.
        $this->getJson("/api/admin/document-templates/{$template->id}/field-map")->assertOk()
            ->assertJsonPath('data.token_values.FamilyName30877', '@nom')
            ->assertJsonPath('data.token_values.__as__45R.value', '@passeport');
    }

    public function test_un_conseiller_ne_peut_pas_modifier_la_correspondance(): void
    {
        $template = $this->mappedTemplate();
        $advisor = $this->inCabinet(fn () => Admin::create(['name' => 'C', 'email' => 'c@example.test', 'password' => 'Secret#2026x', 'role' => 'advisor', 'is_active' => true]));
        Sanctum::actingAs($advisor, ['*'], 'admin');

        $this->getJson("/api/admin/document-templates/{$template->id}/field-map")->assertOk();
        $this->putJson("/api/admin/document-templates/{$template->id}/field-map", ['form_data' => []])->assertForbidden();
    }

    public function test_le_pdf_d_une_invitation_est_prerempli_depuis_le_profil(): void
    {
        $template = $this->mappedTemplate();
        $invitation = $this->inCabinet(function () use ($template) {
            app(\App\Services\ClientProfileService::class)->save($this->client, null, ['passportNumber' => 'AB123456']);
            $inv = Invitation::create([
                'unique_code' => Invitation::generateUniqueCode(), 'client_type' => 'existing', 'client_id' => $this->client->id,
                'email' => 'marie@example.test', 'sent_by' => $this->owner->id, 'expires_at' => now()->addDays(7),
            ]);
            InvitationItem::create(['invitation_id' => $inv->id, 'item_kind' => 'document', 'document_template_id' => $template->id, 'status' => 'pending']);

            return $inv;
        });

        $this->getJson("/api/invitations/{$invitation->unique_code}")->assertOk()
            ->assertJsonPath('data.items.0.prefill.FamilyName30877', 'Tremblay')       // fiche client
            ->assertJsonPath('data.items.0.prefill.__as__45R.value', 'AB123456')       // profil
            ->assertJsonPath('data.items.0.prefill.GivenName1', 'Marie');
    }

    public function test_le_document_d_un_dossier_est_prerempli_pour_l_admin(): void
    {
        $template = $this->mappedTemplate();
        $docId = $this->inCabinet(function () use ($template) {
            $dossier = Dossier::create(['client_id' => $this->client->id, 'scope' => 'client', 'name' => 'Permis']);

            return $dossier->documents()->create(['document_template_id' => $template->id, 'name' => 'IMM 5257', 'template_path' => 'x.pdf'])->id;
        });

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->getJson("/api/admin/dossier-documents/{$docId}")->assertOk()
            ->assertJsonPath('data.prefill.FamilyName30877', 'Tremblay');
    }

    public function test_le_portail_montre_au_client_ses_dossiers_et_ses_demarches_seulement(): void
    {
        $code = $this->inCabinet(function () {
            Dossier::create(['client_id' => $this->client->id, 'scope' => 'client', 'name' => 'Résidence permanente']);
            $other = Client::create(['first_name' => 'Autre', 'last_name' => 'Client', 'email' => 'autre@example.test']);
            Dossier::create(['client_id' => $other->id, 'scope' => 'client', 'name' => 'Dossier d’un autre']);
            $inv = Invitation::create([
                'unique_code' => 'INVPORTAIL1', 'client_type' => 'existing', 'client_id' => $this->client->id,
                'email' => 'marie@example.test', 'sent_by' => $this->owner->id, 'expires_at' => now()->addDays(7),
            ]);
            InvitationItem::create(['invitation_id' => $inv->id, 'item_kind' => 'document', 'status' => 'completed']);
            InvitationItem::create(['invitation_id' => $inv->id, 'item_kind' => 'document', 'status' => 'pending']);

            return $inv->unique_code;
        });

        Sanctum::actingAs($this->client, ['*'], 'client');
        $this->getJson('/api/client/portal')->assertOk()
            ->assertJsonCount(1, 'data.dossiers')
            ->assertJsonPath('data.dossiers.0.name', 'Résidence permanente')
            ->assertJsonPath('data.tasks.0.code', $code)
            ->assertJsonPath('data.tasks.0.items_total', 2)
            ->assertJsonPath('data.tasks.0.items_done', 1);
    }

    public function test_le_client_modifie_son_profil_et_celui_d_un_membre_de_sa_famille(): void
    {
        [$member, $foreignMember] = $this->inCabinet(function () {
            $m = FamilyMember::create(['client_id' => $this->client->id, 'first_name' => 'Léo', 'last_name' => 'Tremblay', 'relationship' => 'enfant']);
            $other = Client::create(['first_name' => 'Autre', 'last_name' => 'Client', 'email' => 'autre@example.test']);
            $f = FamilyMember::create(['client_id' => $other->id, 'first_name' => 'X', 'last_name' => 'Y', 'relationship' => 'conjoint']);

            return [$m, $f];
        });

        Sanctum::actingAs($this->client, ['*'], 'client');

        $this->putJson('/api/client/profile', ['values' => ['passportNumber' => 'ZZ999', 'clefInconnue' => 'x']])->assertOk();
        $this->getJson('/api/client/profile')->assertOk()
            ->assertJsonPath('data.values.passportNumber', 'ZZ999')
            ->assertJsonPath('data.values.lastName', 'Tremblay')
            ->assertJsonMissingPath('data.values.clefInconnue');

        $this->putJson("/api/client/profile?member_id={$member->id}", ['values' => ['passportNumber' => 'LEO111']])->assertOk();
        $this->getJson("/api/client/profile?member_id={$member->id}")->assertJsonPath('data.values.passportNumber', 'LEO111');

        // Le membre d'une autre famille est inaccessible.
        $this->getJson("/api/client/profile?member_id={$foreignMember->id}")->assertNotFound();
    }

    public function test_invitation_au_portail_puis_activation_et_connexion(): void
    {
        // Sans mot de passe, le client ne peut pas se connecter.
        $this->postJson('/api/client/login', ['email' => 'marie@example.test', 'password' => 'nimporte'])->assertStatus(422);

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson("/api/admin/module-clients/{$this->client->id}/portal-invite")->assertOk();

        $token = null;
        Mail::assertQueued(ClientPortalAccessMail::class, function (ClientPortalAccessMail $m) use (&$token) {
            $token = $m->token;

            return $m->purpose === 'invite';
        });

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/client/access/check', ['token' => $token])->assertOk()->assertJsonPath('data.email', 'marie@example.test');
        $this->postJson('/api/client/access/complete', [
            'token' => $token, 'password' => 'Portail#2026x', 'password_confirmation' => 'Portail#2026x',
        ])->assertOk();

        // La session passe par le cookie HttpOnly (le front ne garde pas le jeton).
        $this->postJson('/api/client/login', ['email' => 'marie@example.test', 'password' => 'Portail#2026x'])
            ->assertOk()->assertCookie('auth_token');
    }
}
