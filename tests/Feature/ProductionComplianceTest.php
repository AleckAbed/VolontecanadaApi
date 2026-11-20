<?php

namespace Tests\Feature;

use App\Mail\AppointmentMail;
use App\Mail\DataRequestMail;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\ChatbotSettings;
use App\Models\Client;
use App\Models\DataRequest;
use App\Models\DeletionLog;
use App\Models\Dossier;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * S8 — Mise en production : rappels de rendez-vous, IA désactivée par défaut (2.3),
 * conservation et destruction des dossiers (3.9), droits du client (3.10).
 */
class ProductionComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private Admin $advisor;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-23 12:00:00');

        $this->cabinet = Cabinet::firstOrFail();
        $this->inCabinet(function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->advisor = Admin::create(['name' => 'Conseil', 'email' => 'conseil@example.test', 'password' => 'Secret#2026x', 'role' => 'advisor', 'is_active' => true]);
            $this->client = Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test', 'phone' => '514-555-0000', 'passport_number' => 'AB123456']);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inCabinet(callable $fn): mixed
    {
        return CurrentCabinet::runAs($this->cabinet->id, $fn);
    }

    /** Dossier du client avec un document rempli et un fichier téléversé sur le disque. */
    private function dossierWithFiles(string $status = 'en_cours', ?int $clientId = null): Dossier
    {
        return $this->inCabinet(function () use ($status, $clientId) {
            $d = Dossier::create(['client_id' => $clientId ?? $this->client->id, 'scope' => 'client', 'name' => 'Résidence permanente', 'service_name' => 'Entrée express', 'status' => $status]);
            $dir = "cabinets/{$this->cabinet->id}/dossier-uploads/{$d->id}";
            Documents::disk()->put("{$dir}/passeport.pdf", 'PDF passeport');
            Documents::disk()->put("{$dir}/rempli.pdf", 'PDF rempli');
            DB::table('dossier_uploads')->insert(['cabinet_id' => $this->cabinet->id, 'dossier_id' => $d->id, 'label' => 'Passeport', 'original_filename' => 'passeport.pdf', 'mime_type' => 'application/pdf', 'size' => 12, 'path' => "{$dir}/passeport.pdf", 'created_at' => now(), 'updated_at' => now()]);
            DB::table('dossier_documents')->insert(['cabinet_id' => $this->cabinet->id, 'dossier_id' => $d->id, 'doc_type' => 'ircc', 'name' => 'IMM 0008', 'template_path' => "{$dir}/modele.pdf", 'filled_pdf_path' => "{$dir}/rempli.pdf", 'status' => 'completed', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

            return $d;
        });
    }

    // ─── Rappels de rendez-vous ─────────────────────────────────────────────

    public function test_rappel_envoye_une_seule_fois_la_veille(): void
    {
        $this->inCabinet(function () {
            $base = ['host_admin_id' => $this->owner->id, 'name' => 'Marie', 'email' => 'marie@example.test', 'status' => 'confirmed', 'meeting_mode' => 'video'];
            $a = Appointment::create($base + ['starts_at' => now()->addHours(20), 'ends_at' => now()->addHours(21), 'public_token' => Str::random(40)]);
            $a->forceFill(['created_at' => now()->subDays(3)])->save();
            // Réservé il y a 2 h pour dans 10 h : la confirmation suffit, pas de rappel.
            $late = Appointment::create($base + ['starts_at' => now()->addHours(10), 'ends_at' => now()->addHours(11), 'public_token' => Str::random(40)]);
            $late->forceFill(['created_at' => now()->subHours(2)])->save();
            // Dans 3 jours : trop tôt.
            Appointment::create($base + ['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(), 'public_token' => Str::random(40)]);
        });

        $this->artisan('booking:send-reminders')->assertSuccessful();
        $this->artisan('booking:send-reminders')->assertSuccessful();

        Mail::assertQueued(AppointmentMail::class, 1);
        Mail::assertQueued(AppointmentMail::class, fn ($m) => $m->kind === 'reminder' && $m->hasTo('marie@example.test'));
        $this->assertSame(1, Appointment::acrossCabinets()->whereNotNull('reminder_sent_at')->count());
    }

    // ─── IA (2.3) ───────────────────────────────────────────────────────────

    public function test_assistant_ia_desactive_par_defaut_et_active_avec_consentement(): void
    {
        $this->postJson('/api/admin/chatbot/ask', ['message' => 'Bonjour'])
            ->assertStatus(403)->assertJsonPath('code', 'assistant_disabled');

        $this->putJson('/api/admin/chatbot/settings', ['enabled' => true])->assertStatus(422);
        $this->putJson('/api/admin/chatbot/settings', ['enabled' => true, 'accept_foreign_processing' => true])
            ->assertOk()->assertJsonPath('data.enabled', true);

        $settings = $this->inCabinet(fn () => ChatbotSettings::current());
        $this->assertSame($this->owner->id, $settings->enabled_by);
        $this->assertNotNull($settings->enabled_at);

        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->putJson('/api/admin/chatbot/settings', ['enabled' => false])->assertForbidden();
    }

    // ─── Conservation (3.9) ─────────────────────────────────────────────────

    public function test_date_de_fermeture_suivie_par_le_statut(): void
    {
        $d = $this->dossierWithFiles();
        $this->assertNull($d->closed_at);

        $this->inCabinet(fn () => $d->update(['status' => 'accorde']));
        $this->assertNotNull($d->fresh()->closed_at);

        $this->inCabinet(fn () => $d->update(['status' => 'en_cours']));
        $this->assertNull($d->fresh()->closed_at);
    }

    public function test_destruction_apres_la_duree_de_conservation_avec_preavis(): void
    {
        $this->putJson('/api/admin/compliance/retention', ['retention_years' => 6])->assertOk();

        $old = $this->dossierWithFiles('accorde');
        $soon = $this->dossierWithFiles('refuse');
        $kept = $this->dossierWithFiles('annule');
        $recent = $this->dossierWithFiles('accorde');
        DB::table('dossiers')->where('id', $old->id)->update(['closed_at' => now()->subYears(6)->subDay()]);
        DB::table('dossiers')->where('id', $soon->id)->update(['closed_at' => now()->subYears(6)->addDays(10)]);
        DB::table('dossiers')->where('id', $kept->id)->update(['closed_at' => now()->subYears(7)]);
        $this->postJson("/api/admin/compliance/dossiers/{$kept->id}/hold", ['hold' => true])->assertOk();

        $this->artisan('cabinet:apply-retention --dry-run')->assertSuccessful();
        $this->assertSame(4, Dossier::acrossCabinets()->count());

        $this->artisan('cabinet:apply-retention')->assertSuccessful();

        $this->assertNull(Dossier::acrossCabinets()->find($old->id));
        $this->assertFalse(Documents::disk()->exists("cabinets/{$this->cabinet->id}/dossier-uploads/{$old->id}/passeport.pdf"));
        $this->assertSame(0, DB::table('dossier_uploads')->where('dossier_id', $old->id)->count());
        $this->assertNotNull(Dossier::acrossCabinets()->find($soon->id)?->retention_notified_at);
        $this->assertNotNull(Dossier::acrossCabinets()->find($kept->id));
        $this->assertNotNull(Dossier::acrossCabinets()->find($recent->id));

        $log = DeletionLog::acrossCabinets()->sole();
        $this->assertSame('retention', $log->reason);
        $this->assertSame(2, $log->files_deleted);
        $this->assertStringNotContainsString('Marie', $log->label);
        $this->assertTrue(AdminNotification::acrossCabinets()->where('type', 'retention_upcoming')->where('admin_id', $this->owner->id)->exists());

        $this->getJson('/api/admin/compliance')->assertOk()
            ->assertJsonPath('data.retention_years', 6)
            ->assertJsonCount(1, 'data.log')
            ->assertJsonCount(1, 'data.held');

        Sanctum::actingAs($this->advisor, ['*'], 'admin');
        $this->getJson('/api/admin/compliance')->assertForbidden();
    }

    public function test_supprimer_un_dossier_supprime_ses_fichiers(): void
    {
        $d = $this->dossierWithFiles();
        $this->deleteJson("/api/admin/dossiers/{$d->id}")->assertOk();

        $this->assertSame([], Documents::disk()->allFiles("cabinets/{$this->cabinet->id}/dossier-uploads/{$d->id}"));
        $this->assertSame('manual', DeletionLog::acrossCabinets()->sole()->reason);
    }

    // ─── Droits du client (3.10) ────────────────────────────────────────────

    public function test_le_client_telecharge_ses_donnees_et_seulement_les_siennes(): void
    {
        $this->dossierWithFiles();
        $this->inCabinet(function () {
            $other = Client::create(['first_name' => 'Autre', 'last_name' => 'Client', 'email' => 'autre@example.test']);
            $this->dossierWithFiles('en_cours', $other->id);
        });

        Sanctum::actingAs($this->client, ['*'], 'client');
        $res = $this->get('/api/client/my-data/export')->assertOk();
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zipPath, $res->streamedContent() ?: file_get_contents($res->baseResponse->getFile()->getPathname()));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $json = json_decode($zip->getFromName('mes-donnees.json'), true);
        $this->assertSame('Marie', $json['client']['first_name']);
        $this->assertCount(1, $json['dossiers']);
        $this->assertSame('PDF passeport', $zip->getFromName('fichiers/dossiers/passeport.pdf'));
        $this->assertSame('PDF rempli', $zip->getFromName('fichiers/formulaires/IMM 0008.pdf'));
        $html = $zip->getFromName('mes-donnees.html');
        $this->assertStringContainsString('Vos renseignements personnels', $html);
        $this->assertStringContainsString('Dossiers', $html);
        $this->assertSame(5, $zip->numFiles); // json, html, lisez-moi, 2 fichiers : rien de l'autre client
        $zip->close();
        @unlink($zipPath);
    }

    public function test_demande_de_suppression_traitee_par_le_cabinet(): void
    {
        $d = $this->dossierWithFiles();
        DB::table('invoices')->insert(['cabinet_id' => $this->cabinet->id, 'client_id' => $this->client->id, 'number' => 'F-0001', 'status' => 'paid', 'total_cents' => 10000, 'public_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($this->client, ['*'], 'client');
        $this->postJson('/api/client/data-requests', ['message' => 'Merci de tout effacer'])->assertStatus(422); // confirmation exigée
        $this->postJson('/api/client/data-requests', ['message' => 'Merci de tout effacer', 'confirm' => true])->assertCreated();
        $this->postJson('/api/client/data-requests', ['confirm' => true])->assertStatus(422); // déjà en cours
        $this->assertTrue(AdminNotification::acrossCabinets()->where('type', 'data_request')->where('admin_id', $this->owner->id)->exists());

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $id = $this->inCabinet(fn () => DataRequest::sole()->id);
        $this->postJson("/api/admin/compliance/requests/{$id}/complete", [])->assertStatus(422); // confirmation exigée
        $this->postJson("/api/admin/compliance/requests/{$id}/complete", ['confirm' => true, 'response' => 'C’est fait.'])->assertOk();

        // Client gardé seulement pour ses factures, sans renseignement personnel ni dossier ni fichier.
        $client = Client::acrossCabinets()->find($this->client->id);
        $this->assertNotNull($client->erased_at);
        $this->assertNull($client->passport_number);
        $this->assertNull($client->phone);
        $this->assertFalse($client->is_active);
        $this->assertNull(Dossier::acrossCabinets()->find($d->id));
        $this->assertSame([], Documents::disk()->allFiles("cabinets/{$this->cabinet->id}/dossier-uploads/{$d->id}"));
        $this->assertSame(1, DB::table('invoices')->where('client_id', $this->client->id)->count());
        $this->assertSame('completed', DataRequest::acrossCabinets()->find($id)->status);
        Mail::assertQueued(DataRequestMail::class, fn ($m) => $m->hasTo('marie@example.test'));
    }

    public function test_refus_motive_et_client_sans_facture_supprime_entierement(): void
    {
        Sanctum::actingAs($this->client, ['*'], 'client');
        $this->postJson('/api/client/data-requests', ['confirm' => true])->assertCreated();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $id = $this->inCabinet(fn () => DataRequest::sole()->id);

        $this->postJson("/api/admin/compliance/requests/{$id}/reject", ['response' => 'court'])->assertStatus(422);
        $this->postJson("/api/admin/compliance/requests/{$id}/reject", ['response' => 'Dossier en cours devant IRCC : nous devons le conserver.'])->assertOk();
        $this->assertSame('rejected', DataRequest::acrossCabinets()->find($id)->status);

        // Suppression manuelle d'un client sans facture : la fiche disparaît.
        $this->deleteJson("/api/admin/module-clients/{$this->client->id}")->assertOk();
        $this->assertNull(Client::acrossCabinets()->find($this->client->id));
    }
}
