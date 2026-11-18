<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Collaborator;
use App\Models\Dossier;
use App\Models\Invitation;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * S2 — Un cabinet ne doit jamais voir ni modifier les données d'un autre.
 */
class CabinetIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinetA;
    private Cabinet $cabinetB;
    private Admin $adminA;
    private Admin $adminB;
    private Client $clientA;
    private Client $clientB;
    private Dossier $dossierB;
    private Invitation $invitationB;

    protected function setUp(): void
    {
        parent::setUp();

        // La migration crée le cabinet n° 1 : on s'en sert comme cabinet A.
        $this->cabinetA = Cabinet::firstOrFail();
        $this->cabinetB = Cabinet::create(['name' => 'Cabinet B', 'slug' => 'cabinet-b']);

        CurrentCabinet::runAs($this->cabinetA->id, function () {
            $this->adminA = Admin::create(['name' => 'Admin A', 'email' => 'admin-a@example.test', 'password' => 'secret-a-123', 'role' => 'admin', 'is_active' => true]);
            $this->clientA = Client::create(['first_name' => 'Alice', 'last_name' => 'A', 'email' => 'alice@example.test', 'password' => 'secret-123']);
        });

        CurrentCabinet::runAs($this->cabinetB->id, function () {
            $this->adminB = Admin::create(['name' => 'Admin B', 'email' => 'admin-b@example.test', 'password' => 'secret-b-123', 'role' => 'admin', 'is_active' => true]);
            $this->clientB = Client::create(['first_name' => 'Bruno', 'last_name' => 'B', 'email' => 'bruno@example.test', 'password' => 'secret-123']);
            $this->dossierB = Dossier::create(['client_id' => $this->clientB->id, 'scope' => 'client', 'name' => 'Dossier secret B']);
            $this->invitationB = Invitation::create([
                'unique_code' => Invitation::generateUniqueCode(),
                'client_type' => 'existing',
                'client_id' => $this->clientB->id,
                'email' => 'bruno@example.test',
                'sent_by' => $this->adminB->id,
                'expires_at' => now()->addDays(7),
            ]);
        });

        CurrentCabinet::clear();
    }

    private function actingAsAdmin(Admin $admin): void
    {
        Sanctum::actingAs($admin, ['*'], 'admin');
    }

    public function test_sans_cabinet_courant_aucune_donnee_n_est_visible(): void
    {
        $this->assertSame(0, Client::count());
        $this->assertSame(0, Dossier::count());
    }

    public function test_un_admin_ne_liste_que_les_clients_de_son_cabinet(): void
    {
        $this->actingAsAdmin($this->adminA);

        $emails = collect($this->getJson('/api/admin/module-clients')->assertOk()->json('data.data'))->pluck('email');

        $this->assertTrue($emails->contains('alice@example.test'));
        $this->assertFalse($emails->contains('bruno@example.test'));
    }

    public function test_un_admin_ne_peut_pas_ouvrir_le_dossier_d_un_autre_cabinet(): void
    {
        $this->actingAsAdmin($this->adminA);

        $this->getJson("/api/admin/dossiers/{$this->dossierB->id}")->assertNotFound();
        $this->getJson("/api/admin/module-clients/{$this->clientB->id}")->assertNotFound();

        // Contre-épreuve : l'admin du cabinet B, lui, y a accès.
        $this->actingAsAdmin($this->adminB);
        $this->getJson("/api/admin/dossiers/{$this->dossierB->id}")->assertOk();
    }

    public function test_un_admin_ne_peut_pas_rattacher_un_dossier_au_client_d_un_autre_cabinet(): void
    {
        $this->actingAsAdmin($this->adminA);

        $this->postJson('/api/admin/dossiers', [
            'client_id' => $this->clientB->id,
            'scope' => 'client',
            'name' => 'Tentative',
        ])->assertStatus(422);

        $this->assertSame(1, CurrentCabinet::withoutScope(fn () => Dossier::count()));
    }

    public function test_un_client_cree_est_rattache_au_cabinet_de_l_admin(): void
    {
        $this->actingAsAdmin($this->adminA);

        $this->postJson('/api/admin/module-clients', [
            'client_type' => 'single',
            'first_name' => 'Nouveau',
            'last_name' => 'Client',
            'email' => 'nouveau@example.test',
            'password' => 'secret-123',
        ])->assertSuccessful();

        $client = CurrentCabinet::withoutScope(fn () => Client::where('email', 'nouveau@example.test')->firstOrFail());
        $this->assertSame($this->cabinetA->id, (int) $client->cabinet_id);
    }

    public function test_le_meme_courriel_client_est_permis_dans_deux_cabinets(): void
    {
        $this->actingAsAdmin($this->adminA);

        $this->postJson('/api/admin/module-clients', [
            'client_type' => 'single',
            'first_name' => 'Bruno',
            'last_name' => 'Aussi chez A',
            'email' => 'bruno@example.test',
            'password' => 'secret-123',
        ])->assertSuccessful();

        $this->assertSame(2, CurrentCabinet::withoutScope(fn () => Client::where('email', 'bruno@example.test')->count()));
    }

    public function test_un_lien_public_resout_le_cabinet_depuis_son_code(): void
    {
        $this->getJson("/api/invitations/{$this->invitationB->unique_code}")->assertOk();
        $this->getJson('/api/invitations/code-inexistant')->assertNotFound();
    }

    public function test_un_collaborateur_ne_voit_pas_le_dossier_d_un_autre_cabinet(): void
    {
        $collabA = CurrentCabinet::runAs($this->cabinetA->id, fn () => Collaborator::create([
            'first_name' => 'Colla', 'last_name' => 'A', 'email' => 'colla@example.test', 'password' => 'secret-123', 'is_active' => true,
        ]));

        // Donnée corrompue simulée : le dossier B est assigné au collaborateur du cabinet A.
        CurrentCabinet::withoutScope(fn () => Dossier::whereKey($this->dossierB->id)->update(['collaborator_id' => $collabA->id]));

        Sanctum::actingAs($collabA, ['*'], 'collaborator');

        $this->getJson("/api/collaborator/dossiers/{$this->dossierB->id}")->assertNotFound();
        $this->assertEmpty($this->getJson('/api/collaborator/dossiers')->assertOk()->json('data'));
    }

    public function test_un_cabinet_suspendu_est_bloque(): void
    {
        $this->cabinetA->update(['is_active' => false]);
        $this->actingAsAdmin($this->adminA);

        $this->getJson('/api/admin/module-clients')->assertForbidden();
    }

    public function test_la_connexion_admin_fonctionne_et_ouvre_son_cabinet(): void
    {
        $token = $this->postJson('/api/admin/login', [
            'email' => 'admin-b@example.test',
            'password' => 'secret-b-123',
        ])->assertOk()->json('data.token');

        $emails = collect(
            $this->withToken($token)->getJson('/api/admin/module-clients')->assertOk()->json('data.data')
        )->pluck('email');

        $this->assertEquals(['bruno@example.test'], $emails->all());
    }

    public function test_les_fichiers_televerses_sont_ranges_par_cabinet(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin($this->adminA);

        $this->post('/api/admin/file-manager/items', [
            'files' => [UploadedFile::fake()->create('piece.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $files = Storage::disk('local')->allFiles();
        $this->assertNotEmpty($files);
        foreach ($files as $path) {
            $this->assertStringStartsWith("cabinets/{$this->cabinetA->id}/", $path);
        }
    }

    public function test_creer_dans_un_autre_cabinet_ou_changer_de_cabinet_est_refuse(): void
    {
        CurrentCabinet::runAs($this->cabinetA->id, function () {
            // Affectation en masse : cabinet_id n'est pas « fillable », il est ignoré.
            $viaCreate = Client::create(['cabinet_id' => $this->cabinetB->id, 'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.test', 'password' => 'secret-123']);
            $this->assertSame($this->cabinetA->id, (int) $viaCreate->cabinet_id);

            // Affectation directe vers un autre cabinet : refusée.
            try {
                $client = new Client(['first_name' => 'Z', 'last_name' => 'Z', 'email' => 'z@example.test', 'password' => 'secret-123']);
                $client->cabinet_id = $this->cabinetB->id;
                $client->save();
                $this->fail('La création dans un autre cabinet aurait dû être refusée.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->expectException(LogicException::class);
            $this->clientA->cabinet_id = $this->cabinetB->id;
            $this->clientA->save();
        });
    }
}
