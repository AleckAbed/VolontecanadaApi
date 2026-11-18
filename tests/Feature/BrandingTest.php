<?php

namespace Tests\Feature;

use App\Mail\DataRequestMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\DataRequest;
use App\Models\QuestionnaireRequest;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 1.9 — Image de marque du cabinet : profil, logo public, courriels, liens publics. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private Admin $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cabinet = Cabinet::firstOrFail();
        $this->cabinet->update(['name' => 'Cabinet Tremblay']);
        CurrentCabinet::runAs($this->cabinet->id, function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->advisor = Admin::create(['name' => 'Conseil', 'email' => 'conseil@example.test', 'password' => 'Secret#2026x', 'role' => 'advisor', 'is_active' => true]);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    public function test_profil_couleur_et_logo_du_cabinet(): void
    {
        $this->putJson('/api/admin/cabinet/profile', ['brand_color' => '#abc'])->assertStatus(422);
        $this->putJson('/api/admin/cabinet/profile', ['brand_color' => '#0F766E', 'website' => 'https://tremblay.example'])
            ->assertOk()->assertJsonPath('data.branding.color', '#0f766e')->assertJsonPath('data.branding.color_dark', '#0c5e58');

        // Pas de SVG (script possible) ni de fichier qui n'est pas une image.
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/api/admin/cabinet/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/admin/cabinet/logo', ['logo' => $this->png('logo.png')], ['Accept' => 'application/json'])->assertOk();
        $first = $this->cabinet->fresh()->logo_path;
        $this->post('/api/admin/cabinet/logo', ['logo' => $this->png('logo2.png')], ['Accept' => 'application/json'])->assertOk();
        $second = $this->cabinet->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        $this->assertFalse(Documents::disk()->exists($first)); // l'ancien est supprimé

        $url = $this->getJson('/api/admin/me')->assertOk()->json('data.cabinet.branding.logo_url');
        $this->assertStringContainsString("/api/cabinets/{$this->cabinet->slug}/logo", $url);

        // Logo servi sans authentification ; rien pour un cabinet inconnu.
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $res = $this->get("/api/cabinets/{$this->cabinet->slug}/logo")->assertOk();
        $this->assertStringStartsWith('image/', $res->headers->get('Content-Type'));
        $this->get('/api/cabinets/inconnu/logo')->assertNotFound();

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->deleteJson('/api/admin/cabinet/logo')->assertOk()->assertJsonPath('data.branding.logo_url', null);
        $this->assertFalse(Documents::disk()->exists($second));
    }

    public function test_seuls_les_gestionnaires_modifient_le_profil(): void
    {
        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/cabinet/profile')->assertOk();
        $this->putJson('/api/admin/cabinet/profile', ['name' => 'Autre nom'])->assertForbidden();
        $this->post('/api/admin/cabinet/logo', ['logo' => $this->png('logo.png')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_les_courriels_portent_le_nom_et_la_couleur_du_cabinet(): void
    {
        $this->cabinet->update(['brand_color' => '#7c3aed']);
        $dr = CurrentCabinet::runAs($this->cabinet->id, fn () => DataRequest::create([
            'client_name' => 'Marie', 'client_email' => 'marie@example.test', 'status' => 'rejected', 'response' => 'Dossier en cours.',
        ]));

        $html = (new DataRequestMail($dr, 'Cabinet Tremblay'))->render();
        $this->assertStringContainsString('Cabinet Tremblay', $html);
        $this->assertStringNotContainsString('Volonté Canada', $html);

        // Couleur du cabinet (#7c3aed, assombrie à 80 % pour le titre).
        $this->assertStringContainsString('#632ebe', $html);
        $this->assertStringNotContainsString('#1d4ed8', $html);
    }

    public function test_marque_d_un_lien_public_par_son_code(): void
    {
        $qr = CurrentCabinet::runAs($this->cabinet->id, fn () => QuestionnaireRequest::create([
            'unique_code' => 'QRCODE123', 'email' => 'marie@example.test', 'form_type' => 'questionnaire_demandeur_001',
            'status' => 'pending', 'expires_at' => now()->addDays(5),
        ]));

        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $this->getJson("/api/branding/questionnaire/{$qr->unique_code}")->assertOk()->assertJsonPath('data.name', 'Cabinet Tremblay');
        $this->getJson('/api/branding/questionnaire/INCONNU')->assertNotFound();
    }

    /** PNG valide de 300 × 100 px, sans l'extension GD. */
    private function png(string $name): UploadedFile
    {
        $w = 300;
        $h = 100;
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $raw = str_repeat(chr(0) . str_repeat(chr(0x25) . chr(0x63) . chr(0xEB), $w), $h);
        $png = chr(0x89) . 'PNG' . "\r\n" . chr(0x1A) . "\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw))
            . $chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}
