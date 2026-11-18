<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\CatalogTemplate;
use App\Models\DocumentTemplate;
use App\Models\PlatformAdmin;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Catalogue de modèles partagé : préparé dans un cabinet, publié par la plateforme, importé par les autres. */
class CatalogTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $source;
    private Cabinet $other;
    private PlatformAdmin $platform;
    private Admin $otherOwner;
    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->source = Cabinet::firstOrFail();
        $this->other = Cabinet::create(['name' => 'Autre cabinet', 'slug' => 'autre-cabinet', 'is_active' => true]);
        $this->platform = PlatformAdmin::create(['name' => 'Éditeur', 'email' => 'editeur@example.test', 'password' => 'Secret#2026xyz']);

        $this->template = CurrentCabinet::runAs($this->source->id, function () {
            $owner = Admin::create(['name' => 'Source', 'email' => 'source@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $path = "cabinets/{$this->source->id}/document-templates/imm5257.pdf";
            Documents::disk()->put($path, '%PDF IMM 5257');
            $t = DocumentTemplate::create(['name' => 'IMM 5257', 'doc_type' => 'ircc', 'category' => 'ircc', 'target_location' => 'any', 'pdf_path' => $path, 'created_by' => $owner->id, 'is_active' => true]);
            $t->forceFill(['field_map' => ['nom' => '@nom', 'prenom' => '@prenom']])->save();

            return $t;
        });
        $this->otherOwner = CurrentCabinet::runAs($this->other->id, fn () => Admin::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]));
        CurrentCabinet::clear();
    }

    private function publish(array $extra = []): array
    {
        Sanctum::actingAs($this->platform, ['*'], 'platform');

        return $this->postJson('/api/platform/catalog', ['cabinet_id' => $this->source->id, 'template_id' => $this->template->id] + $extra)->json('data');
    }

    public function test_publier_puis_importer_dans_un_autre_cabinet(): void
    {
        $item = $this->publish();
        $this->assertSame(2, $item['fields']);
        $this->assertFalse($item['is_published']);

        // Pas encore publié : invisible des cabinets.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->otherOwner, ['*'], 'admin');
        $this->getJson('/api/admin/catalog')->assertOk()->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->platform, ['*'], 'platform');
        $this->patchJson("/api/platform/catalog/{$item['id']}", ['is_published' => true])->assertOk();

        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        Sanctum::actingAs($this->otherOwner, ['*'], 'admin');
        $this->getJson('/api/admin/catalog')->assertOk()->assertJsonPath('data.0.name', 'IMM 5257')->assertJsonPath('data.0.imported_template_id', null);
        $id = $this->postJson("/api/admin/catalog/{$item['id']}/import")->assertCreated()->json('data.id');

        $imported = DocumentTemplate::acrossCabinets()->find($id);
        $this->assertSame($this->other->id, $imported->cabinet_id);
        $this->assertSame(['nom' => '@nom', 'prenom' => '@prenom'], $imported->field_map);
        $this->assertStringStartsWith("cabinets/{$this->other->id}/", $imported->pdf_path);
        $this->assertSame('%PDF IMM 5257', Documents::disk()->get($imported->pdf_path));
        $this->assertSame(1, CatalogTemplate::find($item['id'])->imports_count);
    }

    public function test_nouvelle_version_et_mise_a_jour_du_modele_importe(): void
    {
        $item = $this->publish();
        CatalogTemplate::whereKey($item['id'])->update(['is_published' => true]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->otherOwner, ['*'], 'admin');
        $id = $this->postJson("/api/admin/catalog/{$item['id']}/import")->assertCreated()->json('data.id');

        // La plateforme corrige la correspondance et publie la version 2.
        CurrentCabinet::runAs($this->source->id, fn () => $this->template->forceFill(['field_map' => ['nom' => '@nom', 'prenom' => '@prenom', 'naissance' => '@date_naissance']])->save());
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $this->assertSame(2, $this->publish(['catalog_template_id' => $item['id']])['version']);

        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        Sanctum::actingAs($this->otherOwner, ['*'], 'admin');
        $this->getJson('/api/admin/catalog')->assertJsonPath('data.0.update_available', true);
        $this->postJson("/api/admin/catalog/{$item['id']}/import")->assertOk()->assertJsonPath('data.id', $id);
        $this->assertCount(3, DocumentTemplate::acrossCabinets()->find($id)->field_map);
        $this->getJson('/api/admin/catalog')->assertJsonPath('data.0.update_available', false);
    }

    public function test_regles(): void
    {
        // Un modèle sans correspondance ne peut pas être publié.
        CurrentCabinet::runAs($this->source->id, fn () => $this->template->forceFill(['field_map' => null])->save());
        CurrentCabinet::clear();
        Sanctum::actingAs($this->platform, ['*'], 'platform');
        $this->postJson('/api/platform/catalog', ['cabinet_id' => $this->source->id, 'template_id' => $this->template->id])->assertStatus(422);

        // Seuls les gestionnaires importent ; le personnel d'un cabinet n'accède pas à la console.
        $this->app['auth']->forgetGuards();
        $advisor = CurrentCabinet::runAs($this->other->id, fn () => Admin::create(['name' => 'Conseil', 'email' => 'c@example.test', 'password' => 'Secret#2026x', 'role' => 'advisor', 'is_active' => true]));
        CurrentCabinet::clear();
        $catalog = CatalogTemplate::create(['name' => 'X', 'pdf_path' => 'catalog/x.pdf', 'is_published' => true]);
        Sanctum::actingAs($advisor, ['*'], 'admin');
        $this->postJson("/api/admin/catalog/{$catalog->id}/import")->assertForbidden();
        $this->getJson('/api/platform/catalog')->assertUnauthorized();
    }
}
