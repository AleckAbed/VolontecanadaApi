<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S21 — Le propriétaire ouvre ou masque des menus à ses membres, par rôle puis par
 * membre ; l'API applique le même droit que le menu.
 */
class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;

    private Admin $owner;

    private Admin $advisor;

    private Admin $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cabinet = Cabinet::firstOrFail();
        $this->cabinet->forceFill(['require_two_factor' => false])->save();
        $make = fn (string $role) => CurrentCabinet::runAs($this->cabinet->id, fn () => Admin::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test", 'password' => 'Motdepasse#2026', 'role' => $role, 'is_active' => true,
        ]));
        $this->owner = $make('owner');
        $this->advisor = $make('advisor');
        $this->assistant = $make('assistant');
    }

    public function test_par_defaut_tout_est_ouvert(): void
    {
        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.modules.invoices', true);
        $this->getJson('/api/admin/invoices')->assertOk();
    }

    public function test_fermeture_par_role_appliquee_par_l_api(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson('/api/admin/team/module-access', ['roles' => ['advisor' => ['invoices' => false, 'statistics' => false]]])->assertOk();
        $this->getJson('/api/admin/team/module-access')->assertOk()
            ->assertJsonPath('data.roles.0.value', 'advisor')
            ->assertJsonPath('data.roles.0.modules.invoices', false)
            ->assertJsonPath('data.roles.1.modules.invoices', true);

        // Le propriétaire garde tout.
        $this->getJson('/api/admin/invoices')->assertOk();

        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/me')->assertJsonPath('data.modules.invoices', false)->assertJsonPath('data.modules.deadlines', true);
        $this->getJson('/api/admin/invoices')->assertForbidden()->assertJsonPath('code', 'module_disabled');
        $this->getJson('/api/admin/statistics/overview')->assertForbidden();
        $this->getJson('/api/admin/deadlines')->assertOk();

        // L'adjoint n'est pas touché.
        Sanctum::actingAs($this->assistant, ['*'], 'admin');
        $this->getJson('/api/admin/invoices')->assertOk();
    }

    public function test_reglage_du_membre_l_emporte_sur_le_role(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson('/api/admin/team/module-access', ['roles' => ['assistant' => ['invoices' => false]]])->assertOk();
        $this->putJson("/api/admin/team/{$this->assistant->id}/module-access", ['overrides' => ['invoices' => true, 'companies' => false]])
            ->assertOk()->assertJsonPath('data.effective.invoices', true)->assertJsonPath('data.effective.companies', false);

        Sanctum::actingAs($this->assistant->fresh(), ['*'], 'admin');
        $this->getJson('/api/admin/invoices')->assertOk();
        $this->getJson('/api/admin/companies')->assertForbidden();
    }

    public function test_lecture_partagee_reste_permise_mais_pas_les_modifications(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson('/api/admin/team/module-access', ['roles' => ['advisor' => ['envois' => false]]])->assertOk();

        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        // La liste des envois sert aussi depuis un dossier : lecture permise.
        $this->getJson('/api/admin/invitations')->assertOk();
        $this->postJson('/api/admin/invitations', [])->assertForbidden();
    }

    public function test_seuls_les_gestionnaires_reglent_les_menus(): void
    {
        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/team/module-access')->assertForbidden();
        $this->putJson('/api/admin/team/module-access', ['roles' => []])->assertForbidden();

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson("/api/admin/team/{$this->owner->id}/module-access", ['overrides' => ['invoices' => false]])->assertForbidden();
    }
}
