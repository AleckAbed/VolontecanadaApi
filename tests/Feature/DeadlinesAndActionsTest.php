<?php

namespace Tests\Feature;

use App\Mail\DeadlineDigestMail;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Deadline;
use App\Models\Dossier;
use App\Models\Invitation;
use App\Models\InvitationItem;
use App\Services\ClientProfileService;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S5 — Échéances, tableau « action requise », notifications et rappels.
 */
class DeadlinesAndActionsTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private Client $client;
    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(9, 0));

        $this->cabinet = Cabinet::firstOrFail();
        $this->inCabinet(function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->client = Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test']);
            $this->dossier = Dossier::create(['client_id' => $this->client->id, 'scope' => 'client', 'name' => 'Permis de travail', 'status' => 'en_cours']);
        });
    }

    private function inCabinet(callable $fn): mixed
    {
        return CurrentCabinet::runAs($this->cabinet->id, $fn);
    }

    private function invitation(array $attrs = []): Invitation
    {
        return $this->inCabinet(fn () => Invitation::create($attrs + [
            'unique_code' => Invitation::generateUniqueCode(), 'client_type' => 'existing', 'client_id' => $this->client->id,
            'email' => 'marie@example.test', 'sent_by' => $this->owner->id, 'expires_at' => now()->addDays(14), 'sent_at' => now(),
            'allow_uploads' => true,
        ]));
    }

    public function test_les_echeances_reunissent_saisies_dossiers_et_profils_par_urgence(): void
    {
        $this->inCabinet(function () {
            Deadline::create(['label' => 'Biométrie', 'type' => 'biometrics', 'due_date' => '2026-11-17', 'dossier_id' => $this->dossier->id]); // en retard
            Deadline::create(['label' => 'Examen médical', 'type' => 'medical', 'due_date' => '2026-11-22']);                                 // cette semaine
            Deadline::create(['label' => 'Lointain', 'type' => 'other', 'due_date' => '2027-06-01']);                                         // plus tard
            $this->dossier->update(['deadline_at' => '2026-11-30']);                                                                          // 11 j : rappel (14 j)
            app(ClientProfileService::class)->save($this->client, null, ['passportExpiryDate' => '2027-03-01']);                              // ~100 j : rappel (180 j)
            app(ClientProfileService::class)->save($this->client, null, ['permitExpiryDate' => '2028-01-01']);                                // hors fenêtre : ignoré
        });

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $items = collect($this->getJson('/api/admin/deadlines')->assertOk()->json('data.items'));

        $this->assertSame('overdue', $items->firstWhere('label', 'Biométrie')['urgency']);
        $this->assertSame('Marie Tremblay', $items->firstWhere('label', 'Biométrie')['client']['name']); // client déduit du dossier
        $this->assertSame('this_week', $items->firstWhere('label', 'Examen médical')['urgency']);
        $this->assertSame('later', $items->firstWhere('label', 'Lointain')['urgency']);
        $this->assertSame('soon', $items->firstWhere('source', 'dossier')['urgency']);
        $this->assertSame('passport_expiry', $items->firstWhere('source', 'profile')['type']);
        $this->assertCount(1, $items->where('source', 'profile'), 'L’expiration du permis, lointaine, ne doit pas remonter.');
    }

    public function test_une_echeance_se_cree_se_marque_faite_et_un_adjoint_ne_peut_pas_la_supprimer(): void
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson('/api/admin/deadlines', ['label' => 'Dépôt', 'type' => 'filing', 'due_date' => '2026-12-01', 'dossier_id' => $this->dossier->id])->assertCreated();
        $id = $this->inCabinet(fn () => Deadline::firstOrFail()->id);

        $this->postJson("/api/admin/deadlines/{$id}/complete")->assertOk();
        $this->assertEmpty($this->getJson('/api/admin/deadlines')->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/admin/deadlines?include_done=1')->json('data.items'));

        $assistant = $this->inCabinet(fn () => Admin::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'Secret#2026x', 'role' => 'assistant', 'is_active' => true]));
        Sanctum::actingAs($assistant, ['*'], 'admin');
        $this->deleteJson("/api/admin/deadlines/{$id}")->assertForbidden();
    }

    public function test_un_dossier_d_un_autre_cabinet_ne_peut_pas_recevoir_d_echeance(): void
    {
        $other = Cabinet::create(['name' => 'Autre', 'slug' => 'autre']);
        $foreignDossierId = CurrentCabinet::runAs($other->id, function () {
            $c = Client::create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.test']);

            return Dossier::create(['client_id' => $c->id, 'scope' => 'client', 'name' => 'Secret'])->id;
        });

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson('/api/admin/deadlines', ['label' => 'X', 'type' => 'other', 'due_date' => '2026-12-01', 'dossier_id' => $foreignDossierId])
            ->assertStatus(422);
    }

    public function test_l_envoi_termine_notifie_l_expediteur_et_apparait_a_reviser(): void
    {
        $inv = $this->invitation();
        $this->inCabinet(function () use ($inv) {
            $item = InvitationItem::create(['invitation_id' => $inv->id, 'item_kind' => 'document', 'status' => 'pending']);
            $item->update(['status' => 'completed']);
            $inv->recomputeStatus();
            $inv->recomputeStatus(); // une seconde fois : pas de doublon
        });

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.items.0.title', 'Marie Tremblay a terminé son envoi');

        $bucket = fn () => collect($this->getJson('/api/admin/actions')->json('data.buckets'))->firstWhere('key', 'to_review');
        $this->assertSame(1, $bucket()['count']);

        $this->postJson("/api/admin/invitations/{$inv->id}/reviewed")->assertOk();
        $this->assertSame(0, $bucket()['count']);

        $notifId = $this->getJson('/api/admin/notifications')->json('data.items.0.id');
        $this->postJson("/api/admin/notifications/{$notifId}/read")->assertOk();
        $this->getJson('/api/admin/notifications')->assertJsonPath('data.unread', 0);
    }

    public function test_un_document_televerse_par_le_client_notifie_le_cabinet(): void
    {
        Storage::fake('local');
        $inv = $this->invitation();

        $this->post("/api/invitations/{$inv->unique_code}/uploads", [
            'file' => UploadedFile::fake()->create('passeport.pdf', 20, 'application/pdf'),
            'label' => 'Passeport',
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $n = $this->inCabinet(fn () => AdminNotification::where('admin_id', $this->owner->id)->firstOrFail());
        $this->assertSame('client_upload', $n->type);
        $this->assertSame('passeport.pdf', $n->body);
    }

    public function test_le_tableau_action_requise_compte_les_dossiers_en_attente_et_les_liens_qui_expirent(): void
    {
        $this->inCabinet(fn () => $this->dossier->update(['status' => 'soumis']));
        $this->invitation(['expires_at' => now()->addDays(2)]);

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $buckets = collect($this->getJson('/api/admin/actions')->assertOk()->json('data.buckets'))->keyBy('key');

        $this->assertSame(1, $buckets['awaiting_decision']['count']);
        $this->assertSame(1, $buckets['expiring_links']['count']);
        $this->assertSame('/admin/dossiers/' . $this->dossier->id, $buckets['awaiting_decision']['items'][0]['link']);
    }

    public function test_les_rappels_quotidiens_notifient_une_seule_fois_et_envoient_un_recapitulatif(): void
    {
        $this->inCabinet(function () {
            Deadline::create(['label' => 'Biométrie', 'type' => 'biometrics', 'due_date' => '2026-11-21', 'dossier_id' => $this->dossier->id]);
            Admin::create(['name' => 'Adjoint', 'email' => 'adjoint@example.test', 'password' => 'Secret#2026x', 'role' => 'assistant', 'is_active' => true]);
        });

        $this->artisan('cabinet:deadline-reminders')->assertSuccessful();
        $this->artisan('cabinet:deadline-reminders')->assertSuccessful(); // relancée le même jour : rien de nouveau

        $notifs = $this->inCabinet(fn () => AdminNotification::all());
        $this->assertCount(1, $notifs, 'Un seul rappel, au propriétaire (l’adjoint ne suit pas les échéances).');
        $this->assertSame('deadline_reminder', $notifs->first()->type);
        Mail::assertSent(DeadlineDigestMail::class, 1);

        // Une fois dépassée, l'échéance notifie une seconde fois, comme « en retard ».
        $this->travel(3)->days();
        $this->artisan('cabinet:deadline-reminders')->assertSuccessful();
        $this->assertSame(['deadline_overdue', 'deadline_reminder'], $this->inCabinet(fn () => AdminNotification::orderBy('type')->pluck('type')->all()));
    }
}
