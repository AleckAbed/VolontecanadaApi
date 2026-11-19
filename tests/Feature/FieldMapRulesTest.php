<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\DocumentTemplate;
use App\Models\Dossier;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Préremplissage des PDF : formats de date, cases à cocher, boutons radio et listes.
 */
class FieldMapRulesTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private DocumentTemplate $template;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cabinet = Cabinet::firstOrFail();
        CurrentCabinet::runAs($this->cabinet->id, function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->template = DocumentTemplate::create(['name' => 'IMM 5257', 'pdf_path' => 'x.pdf', 'created_by' => $this->owner->id]);
            $this->client = Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test', 'date_of_birth' => '1990-04-07']);
            ClientProfile::create(['client_id' => $this->client->id, 'family_member_id' => null, 'data' => ['sex' => 'female', 'currentMaritalStatus' => 'married']]);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/document-templates/{$this->template->id}/field-map{$suffix}";
    }

    /** Préremplissage d'un document de dossier tiré du modèle, tel que l'admin l'ouvre. */
    private function prefill(): array
    {
        $docId = CurrentCabinet::runAs($this->cabinet->id, function () {
            $dossier = Dossier::create(['client_id' => $this->client->id, 'scope' => 'client', 'name' => 'Visa visiteur']);

            return $dossier->documents()->create(['document_template_id' => $this->template->id, 'name' => 'IMM 5257', 'template_path' => 'x.pdf'])->id;
        });

        return $this->getJson("/api/admin/dossier-documents/{$docId}")->assertOk()->json('data.prefill');
    }

    public function test_les_dates_prennent_le_format_du_jeton_et_peuvent_etre_decoupees(): void
    {
        $this->putJson($this->url(), ['form_data' => [
            'DOBFull' => '@date_naissance:jj/mm/aaaa',
            'DOBYear' => '@date_naissance:aaaa',
            '__as__DOBMonth' => ['value' => '@date_naissance:mm'],
            'DOBDay' => '@Date_Naissance:JJ',
            'Bad' => '@date_naissance:zz',
            'Name' => '@nom',
        ]])->assertOk()
            ->assertJsonPath('data.mapped_count', 2)
            ->assertJsonPath('data.unknown_tokens', ['@date_naissance:zz']);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.token_values.DOBFull', '@date_naissance:jj/mm/aaaa')
            ->assertJsonPath('data.token_values.__as__DOBMonth.value', '@date_naissance:mm');

        $prefill = $this->prefill();
        $this->assertSame('07/04/1990', $prefill['DOBFull']);
        $this->assertSame('1990', $prefill['DOBYear']);
        $this->assertSame(['value' => '04'], $prefill['__as__DOBMonth']);
        $this->assertSame('07', $prefill['DOBDay']);
        $this->assertSame('Tremblay', $prefill['Name']);
    }

    public function test_cases_radios_et_listes_relies_en_cliquant(): void
    {
        // Case « Féminin » (XFA : clé DOM + stockage pdf.js avec sa valeur « cochée »).
        $g1 = $this->postJson($this->url('/rules'), [
            'key' => 'sex', 'mode' => 'equals', 'equals' => ['female'],
            'targets' => ['SexF' => true, '__as__SexF' => ['value' => '1']],
        ])->assertOk()->json('data.rules.0.group');
        // Case « Masculin » : ne doit pas être cochée pour Marie.
        $this->postJson($this->url('/rules'), [
            'key' => 'sex', 'mode' => 'equals', 'equals' => ['male'],
            'targets' => ['SexM' => true, '__as__SexM' => ['value' => '1']],
        ])->assertOk();
        // Liste de l'état civil : l'option est choisie d'après la valeur et ses libellés.
        $this->postJson($this->url('/rules'), [
            'key' => 'currentMaritalStatus', 'mode' => 'choice', 'targets' => ['Marital' => null, '__as__Marital' => null],
        ])->assertOk()->assertJsonPath('data.mapped_count', 2)->assertJsonCount(3, 'data.rules');

        // Case « Année » de 4 caractères : trop courte pour un jeton, on la relie en cliquant.
        $this->postJson($this->url('/rules'), [
            'key' => 'dateOfBirth', 'mode' => 'text', 'format' => 'aaaa', 'targets' => ['__as__field32197' => null],
        ])->assertOk()->assertJsonPath('data.rules.3.mode', 'text')->assertJsonPath('data.rules.3.format', 'aaaa');

        $this->postJson($this->url('/rules'), ['key' => 'inconnu', 'mode' => 'choice', 'targets' => ['X' => null]])->assertUnprocessable();
        $this->postJson($this->url('/rules'), ['key' => 'dateOfBirth', 'mode' => 'choice', 'format' => 'zz', 'targets' => ['X' => null]])->assertUnprocessable();

        // Enregistrer les jetons texte garde les cases déjà reliées.
        $this->putJson($this->url(), ['form_data' => ['Name' => '@nom']])->assertOk()->assertJsonPath('data.mapped_count', 4);
        $this->getJson($this->url())->assertOk()->assertJsonCount(4, 'data.rules')
            ->assertJsonMissingPath('data.token_values.__as__field32197')
            ->assertJsonMissingPath('data.token_values.SexF');

        $prefill = $this->prefill();
        $this->assertTrue($prefill['SexF']);
        $this->assertSame(['value' => '1'], $prefill['__as__SexF']);
        $this->assertArrayNotHasKey('SexM', $prefill);
        $this->assertArrayNotHasKey('__as__SexM', $prefill);
        $this->assertSame(['choices' => ['married', 'Marié(e)', 'Married']], $prefill['Marital']);
        $this->assertSame('married', $prefill['__as__Marital']['value']);
        $this->assertSame(['value' => '1990'], $prefill['__as__field32197']);

        // Retirer un lien : ses deux clés disparaissent.
        $this->deleteJson($this->url("/rules/{$g1}"))->assertOk()->assertJsonCount(3, 'data.rules');
        $this->assertArrayNotHasKey('SexF', $this->template->fresh()->field_map);
        $this->assertArrayHasKey('SexM', $this->template->fresh()->field_map);
    }

    public function test_un_conseiller_ne_peut_pas_relier_de_case(): void
    {
        $advisor = CurrentCabinet::runAs($this->cabinet->id, fn () => Admin::create(['name' => 'Conseiller', 'email' => 'c@example.test', 'password' => 'Secret#2026x', 'role' => 'advisor', 'is_active' => true]));
        Sanctum::actingAs($advisor, ['*'], 'admin');
        $this->postJson($this->url('/rules'), ['key' => 'sex', 'mode' => 'choice', 'targets' => ['X' => null]])->assertForbidden();
        $this->deleteJson($this->url('/rules/abc'))->assertForbidden();
    }
}
