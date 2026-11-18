<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\Invitation;
use App\Models\QuestionnaireRequest;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 2.16 — Un seul système : tout questionnaire appartient à un envoi. */
class QuestionnaireUnificationTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->cabinet = Cabinet::firstOrFail();
        CurrentCabinet::runAs($this->cabinet->id, function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    private function formTypeId(string $code = 'questionnaire_pstq_pointage'): int
    {
        return DB::table('form_types')->where('cabinet_id', $this->cabinet->id)->where('code', $code)->value('id')
            ?? DB::table('form_types')->insertGetId(['cabinet_id' => $this->cabinet->id, 'code' => $code, 'name' => 'PSTQ', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function questionnaire(array $attrs = []): QuestionnaireRequest
    {
        return CurrentCabinet::runAs($this->cabinet->id, fn () => QuestionnaireRequest::create($attrs + [
            'unique_code' => QuestionnaireRequest::generateUniqueCode(), 'client_type' => 'custom', 'custom_name' => 'Jean Client',
            'email' => 'jean@example.test', 'phone' => '514-555-0000', 'form_type' => 'questionnaire_pstq_pointage',
            'status' => 'in_progress', 'form_data' => ['age' => 30], 'sent_by' => $this->owner->id, 'expires_at' => now()->addDays(10),
        ]));
    }

    public function test_les_questionnaires_seuls_et_orphelins_deviennent_des_envois(): void
    {
        $this->formTypeId();
        $alone = $this->questionnaire();
        $orphan = $this->questionnaire(['status' => 'completed', 'completed_at' => now(), 'invitation_item_id' => 999999]);

        $migration = require database_path('migrations/2026_11_26_000001_move_standalone_questionnaires_to_envois.php');
        $migration->up();

        foreach ([[$alone, 'in_progress'], [$orphan, 'completed']] as [$qr, $status]) {
            $qr = $qr->fresh();
            $item = DB::table('invitation_items')->find($qr->invitation_item_id);
            $this->assertNotNull($item, 'questionnaire rattaché à un élément d’envoi');
            $this->assertSame($qr->unique_code, $item->linked_questionnaire_code);
            $inv = DB::table('invitations')->find($item->invitation_id);
            $this->assertSame($status, $inv->status);
            $this->assertSame('jean@example.test', $inv->email);
            $this->assertSame(['age' => 30], $qr->form_data); // réponses intactes
        }

        // L'envoi affiche le questionnaire avec son statut, et l'ancien détail renvoie vers lui.
        $invId = DB::table('invitation_items')->where('id', $alone->fresh()->invitation_item_id)->value('invitation_id');
        $this->getJson("/api/admin/invitations/{$invId}")->assertOk()->assertJsonPath('data.items.0.status', 'in_progress');
        $this->getJson("/api/admin/questionnaires/{$alone->id}")->assertOk()->assertJsonPath('data.invitation_id', $invId);

        // Le lien déjà envoyé au client fonctionne toujours.
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $this->postJson('/api/questionnaires/verify', ['email' => 'jean@example.test', 'code' => $alone->unique_code])->assertOk();

        // Réversible.
        $migration->down();
        $this->assertNull($alone->fresh()->invitation_item_id);
    }

    public function test_l_envoi_direct_est_retire(): void
    {
        $this->postJson('/api/admin/questionnaires/send', ['client_type' => 'custom'])->assertStatus(405);
        $this->getJson('/api/admin/questionnaires')->assertNotFound();
    }

    public function test_supprimer_un_envoi_supprime_ses_questionnaires_et_fichiers(): void
    {
        $formTypeId = $this->formTypeId();
        $qr = $this->questionnaire();
        $inv = CurrentCabinet::runAs($this->cabinet->id, fn () => Invitation::create([
            'unique_code' => Invitation::generateUniqueCode(), 'client_type' => 'custom', 'custom_name' => 'Jean Client',
            'email' => 'jean@example.test', 'sent_by' => $this->owner->id, 'expires_at' => now()->addDays(7),
        ]));
        $itemId = DB::table('invitation_items')->insertGetId([
            'cabinet_id' => $this->cabinet->id, 'invitation_id' => $inv->id, 'item_kind' => 'form', 'form_type_id' => $formTypeId,
            'linked_questionnaire_code' => $qr->unique_code, 'status' => 'pending', 'sort_order' => 0,
            'pdf_filled_path' => 'cabinets/1/rempli.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $qr->forceFill(['invitation_item_id' => $itemId])->save();
        Documents::disk()->put('cabinets/1/rempli.pdf', 'PDF');

        $this->deleteJson("/api/admin/invitations/{$inv->id}")->assertOk();

        $this->assertNull(QuestionnaireRequest::acrossCabinets()->find($qr->id));
        $this->assertFalse(Documents::disk()->exists('cabinets/1/rempli.pdf'));
        // Le lien du questionnaire ne fonctionne plus.
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
        $this->postJson('/api/questionnaires/verify', ['email' => 'jean@example.test', 'code' => $qr->unique_code])->assertStatus(404);
    }
}
