<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Support\Security\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sécurité avant la mise en ligne : en-têtes, antivirus des fichiers reçus, double
 * authentification par défaut des nouveaux cabinets.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Chaîne de test standard EICAR : reconnue par tous les antivirus, sans danger. */
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /** @var resource|null */
    private $clamd = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Une route de test dans le groupe « api », qui porte les mêmes middlewares que les vraies.
        Route::middleware('api')->post('/api/_test/upload', fn () => response()->json(['success' => true]));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->clamd)) {
            proc_terminate($this->clamd);
            proc_close($this->clamd);
        }
        parent::tearDown();
    }

    /** Lance le faux clamd (vrai protocole INSTREAM, sur TCP) et y branche l'antivirus. */
    private function startClamd(int $connections = 1): void
    {
        $port = random_int(40000, 49000);
        $this->clamd = proc_open([PHP_BINARY, base_path('tests/Support/fake-clamd.php'), (string) $port, (string) $connections],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertSame("ready\n", fgets($pipes[1]));
        config(['security.antivirus.host' => '127.0.0.1', 'security.antivirus.port' => $port, 'security.antivirus.timeout' => 5]);
    }

    public function test_les_reponses_de_l_api_portent_les_en_tetes_de_securite(): void
    {
        $this->getJson('/api/ping')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }

    public function test_le_vrai_protocole_clamav_repere_un_fichier_infecte(): void
    {
        $this->startClamd(2);
        $scanner = app(VirusScanner::class);
        $this->assertNull($scanner->scanBytes('%PDF-1.7 un passeport ordinaire'));
        $this->assertSame('Eicar-Test-Signature', $scanner->scanBytes(self::EICAR));
    }

    public function test_un_fichier_infecte_est_refuse_avant_le_controleur(): void
    {
        $this->startClamd(3);

        $this->post('/api/_test/upload', ['file' => UploadedFile::fake()->createWithContent('passeport.pdf', '%PDF-1.7 propre')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->post('/api/_test/upload', ['docs' => [['file' => UploadedFile::fake()->createWithContent('releve.pdf', self::EICAR)]]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('success', false);
        // PDF rempli envoyé en base64 depuis le visualiseur.
        $this->postJson('/api/_test/upload', ['pdf_base64' => base64_encode(str_repeat('a', 80) . self::EICAR)])
            ->assertStatus(422);
    }

    public function test_antivirus_injoignable_refuse_seulement_s_il_est_exige(): void
    {
        config(['security.antivirus.host' => '127.0.0.1', 'security.antivirus.port' => 1, 'security.antivirus.timeout' => 2]);
        $file = fn () => ['file' => UploadedFile::fake()->createWithContent('a.pdf', 'contenu')];

        $this->post('/api/_test/upload', $file(), ['Accept' => 'application/json'])->assertOk();

        config(['security.antivirus.required' => true]);
        $this->post('/api/_test/upload', $file(), ['Accept' => 'application/json'])->assertStatus(503);
        // Sans fichier, rien à analyser : la requête passe.
        $this->postJson('/api/_test/upload', ['nom' => 'x'])->assertOk();
    }

    public function test_un_nouveau_cabinet_exige_la_double_authentification(): void
    {
        $register = fn (string $email) => $this->postJson('/api/cabinets/register', [
            'cabinet_name' => 'Cabinet ' . $email, 'province' => 'QC', 'name' => 'Propriétaire',
            'email' => $email, 'password' => 'Secret#2026x-Long', 'password_confirmation' => 'Secret#2026x-Long', 'accept_terms' => true,
        ])->assertCreated();

        $register('a@cabinet.test');
        $this->assertTrue((bool) Cabinet::where('name', 'Cabinet a@cabinet.test')->value('require_two_factor'));

        config(['security.two_factor_default' => false]);
        $register('b@cabinet.test');
        $this->assertFalse((bool) Cabinet::where('name', 'Cabinet b@cabinet.test')->value('require_two_factor'));
    }

    public function test_la_verification_de_production_refuse_une_configuration_de_test(): void
    {
        $this->artisan('app:production-check')
            ->expectsOutputToContain('échec(s)')
            ->assertExitCode(1);
    }
}
