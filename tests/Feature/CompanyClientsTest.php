<?php

namespace Tests\Feature;

use App\Mail\ClientPortalAccessMail;
use App\Mail\CompanyAccessMail;
use App\Mail\InvoiceMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\CompanyContact;
use App\Models\Dossier;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Clients « entreprise » : fiche, employés liés, espace entreprise, demandes, facturation. */
class CompanyClientsTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private Client $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cabinet = Cabinet::firstOrFail();
        CurrentCabinet::runAs($this->cabinet->id, function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->employee = Client::create(['first_name' => 'Amina', 'last_name' => 'Diallo', 'email' => 'amina@example.test', 'client_type' => 'family']);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    private function asStaff(): void
    {
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    /** Entreprise, contact avec accès activé, employé lié, dossier demandé par l'entreprise. */
    private function setupCompany(): array
    {
        $companyId = $this->postJson('/api/admin/companies', ['name' => 'Usine Laval inc.', 'business_number' => '1171234567', 'email' => 'factures@usine.test'])->assertCreated()->json('data.id');
        $contactId = $this->postJson("/api/admin/companies/{$companyId}/contacts", ['first_name' => 'Julie', 'last_name' => 'RH', 'email' => 'julie@usine.test'])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/companies/{$companyId}/employees", ['client_id' => $this->employee->id, 'job_title' => 'Soudeuse'])->assertOk();
        $this->postJson("/api/admin/companies/{$companyId}/employees", ['client_id' => $this->employee->id])->assertStatus(422); // déjà liée

        $dossier = CurrentCabinet::runAs($this->cabinet->id, fn () => Dossier::create(['client_id' => $this->employee->id, 'scope' => 'family', 'name' => 'Permis de travail', 'status' => 'en_cours']));
        $this->putJson("/api/admin/dossiers/{$dossier->id}/company", ['company_id' => $companyId])->assertOk();

        // Accès de la contact : invitation, puis choix du mot de passe.
        $this->postJson("/api/admin/companies/{$companyId}/contacts/{$contactId}/invite")->assertOk();
        $token = null;
        Mail::assertQueued(CompanyAccessMail::class, function ($m) use (&$token) { $token = $m->token; return $m->hasTo('julie@usine.test'); });
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $this->postJson('/api/company/access/complete', ['token' => $token, 'password' => 'Usine#2026secret', 'password_confirmation' => 'Usine#2026secret'])->assertOk();

        return [$companyId, $contactId, $dossier];
    }

    private function asCompany(): void
    {
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $contact = CompanyContact::acrossCabinets()->where('email', 'julie@usine.test')->first();
        Sanctum::actingAs($contact, ['*'], 'company');
    }

    public function test_fiche_entreprise_et_espace_entreprise_selon_le_partage(): void
    {
        [$companyId, , $dossier] = $this->setupCompany();
        $this->asStaff();

        $this->getJson("/api/admin/companies/{$companyId}")->assertOk()
            ->assertJsonPath('data.employees.0.name', 'Amina Diallo')
            ->assertJsonPath('data.employees.0.job_title', 'Soudeuse')
            ->assertJsonPath('data.dossiers.0.visibility', 'progress')
            ->assertJsonPath('data.contacts.0.portal', 'active');

        // Connexion à l'espace entreprise.
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $this->postJson('/api/company/login', ['email' => 'julie@usine.test', 'password' => 'Usine#2026secret'])->assertOk()->assertJsonPath('data.contact.company', 'Usine Laval inc.');

        // Par défaut : avancement seulement.
        $this->asCompany();
        $view = $this->getJson('/api/company/portal')->assertOk()->json('data.employees.0.dossiers.0');
        $this->assertSame('Permis de travail', $view['name']);
        $this->assertArrayHasKey('progress', $view);
        $this->assertArrayNotHasKey('items', $view);

        // Le cabinet partage aussi formulaires et documents ; l'employée le voit dans son portail.
        $this->asStaff();
        $this->putJson("/api/admin/dossiers/{$dossier->id}/company", ['company_id' => $companyId, 'company_visibility' => 'full'])->assertOk();
        $this->asCompany();
        $this->assertArrayHasKey('items', $this->getJson('/api/company/portal')->json('data.employees.0.dossiers.0'));
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        Sanctum::actingAs($this->employee, ['*'], 'client');
        $this->getJson('/api/client/portal')->assertOk()->assertJsonPath('data.dossiers.0.company.visibility', 'full');

        // Changement d'employeur : l'entreprise ne voit plus rien de cette personne.
        $this->asStaff();
        $linkId = DB::table('company_client')->where('company_id', $companyId)->value('id');
        $this->postJson("/api/admin/companies/{$companyId}/employees/{$linkId}/end")->assertOk();
        $this->asCompany();
        $this->getJson('/api/company/portal')->assertOk()->assertJsonCount(0, 'data.employees');

        // Un contact d'entreprise n'accède pas aux écrans du cabinet.
        $this->getJson('/api/admin/companies')->assertUnauthorized();
    }

    public function test_demande_d_une_entreprise_acceptee_par_le_cabinet(): void
    {
        [$companyId] = $this->setupCompany();
        $this->asCompany();
        $payload = ['first_name' => 'Karim', 'last_name' => 'Benali', 'email' => 'karim@example.test', 'job_title' => 'Électricien', 'request_type' => 'Permis de travail (EIMT)', 'with_family' => true];
        $this->postJson('/api/company/requests', $payload)->assertStatus(422); // employé informé : case obligatoire
        $this->postJson('/api/company/requests', $payload + ['consent' => true])->assertCreated();

        $this->asStaff();
        $requestId = $this->getJson('/api/admin/company-requests?status=pending')->assertOk()->json('data.0.id');
        $this->postJson("/api/admin/company-requests/{$requestId}/accept", ['dossier_name' => 'Permis de travail — Karim'])->assertOk();

        $karim = Client::acrossCabinets()->where('email', 'karim@example.test')->first();
        $this->assertSame('family', $karim->client_type);
        $this->assertTrue(DB::table('company_client')->where('company_id', $companyId)->where('client_id', $karim->id)->whereNull('ended_at')->exists());
        $this->assertSame($companyId, Dossier::acrossCabinets()->where('client_id', $karim->id)->value('company_id'));
        Mail::assertQueued(ClientPortalAccessMail::class, fn ($m) => $m->hasTo('karim@example.test'));

        // L'entreprise voit le nouvel employé et le statut de sa demande.
        $this->asCompany();
        $portal = $this->getJson('/api/company/portal')->json('data');
        $this->assertContains('Karim Benali', collect($portal['employees'])->pluck('name')->all());
        $this->assertSame('accepted', $portal['requests'][0]['status']);
    }

    public function test_facture_adressee_a_l_entreprise(): void
    {
        [$companyId] = $this->setupCompany();
        $this->asStaff();
        $lines = [['description' => 'Permis de travail', 'quantity' => 1, 'unit_price' => 1500, 'taxable' => true]];
        $otherCompany = $this->postJson('/api/admin/companies', ['name' => 'Autre inc.'])->json('data.id');
        $this->postJson('/api/admin/invoices', ['client_id' => $this->employee->id, 'company_id' => $otherCompany, 'lines' => $lines])->assertStatus(422);

        $invoiceId = $this->postJson('/api/admin/invoices', ['client_id' => $this->employee->id, 'company_id' => $companyId, 'lines' => $lines])->assertCreated()->json('data.id');
        $this->getJson("/api/admin/invoices/{$invoiceId}")->assertOk()->assertJsonPath('data.company.name', 'Usine Laval inc.');
        $this->postJson("/api/admin/invoices/{$invoiceId}/send")->assertOk();
        Mail::assertQueued(InvoiceMail::class, fn ($m) => $m->hasTo('factures@usine.test'));

        // Dans l'espace entreprise, pas dans le portail de l'employée.
        $this->asCompany();
        $this->getJson('/api/company/portal')->assertJsonCount(1, 'data.invoices');
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        Sanctum::actingAs($this->employee, ['*'], 'client');
        $this->getJson('/api/client/invoices')->assertOk()->assertJsonCount(0, 'data');
    }
}
