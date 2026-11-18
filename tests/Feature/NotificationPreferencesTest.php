<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\Cabinet;
use App\Services\NotificationService;
use App\Support\NotificationTypes;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Préférences de notification par personne : cloche et courriel. */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cabinet = Cabinet::firstOrFail();
        $this->owner = CurrentCabinet::runAs($this->cabinet->id, fn () => Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]));
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    private function notify(string $type, ?string $dedupe = null): void
    {
        CurrentCabinet::runAs($this->cabinet->id, fn () => app(NotificationService::class)->notify([$this->owner->id], $type, 'Titre', 'Détail', '/envois/1', $dedupe));
    }

    public function test_valeurs_par_defaut_et_enregistrement_des_seuls_ecarts(): void
    {
        $prefs = collect($this->getJson('/api/admin/notification-preferences')->assertOk()->json('data'))->keyBy('type');
        $this->assertTrue($prefs['client_upload']['app']);
        $this->assertFalse($prefs['client_upload']['email']);
        $this->assertTrue($prefs['data_request']['email']); // délai légal : courriel par défaut
        $this->assertNull($prefs['deadline_digest']['app']); // courriel seulement

        $this->putJson('/api/admin/notification-preferences', ['preferences' => [
            'client_upload' => ['app' => false, 'email' => true],
            'invitation_completed' => ['app' => true, 'email' => false], // = défaut : pas gardé
            'type_inconnu' => ['app' => false],
        ]])->assertOk();

        $this->assertSame(['client_upload' => ['app' => false, 'email' => true]], $this->owner->fresh()->notification_preferences);
    }

    public function test_la_cloche_et_le_courriel_suivent_les_preferences(): void
    {
        // Par défaut : cloche, pas de courriel.
        $this->notify('invitation_completed');
        $this->assertSame(1, AdminNotification::acrossCabinets()->count());
        Mail::assertNotQueued(NotificationMail::class);

        // Courriel seulement, sans doublon pour un même événement.
        $this->owner->forceFill(['notification_preferences' => ['client_upload' => ['app' => false, 'email' => true]]])->save();
        $this->notify('client_upload', 'upload:42');
        $this->notify('client_upload', 'upload:42');
        $this->assertSame(1, AdminNotification::acrossCabinets()->count());
        Mail::assertQueued(NotificationMail::class, 1);
        Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('owner@example.test') && $m->title === 'Titre');
    }

    public function test_recapitulatif_des_echeances_desactivable(): void
    {
        $this->assertTrue(NotificationTypes::wants($this->owner, 'deadline_digest', 'email'));
        $this->owner->forceFill(['notification_preferences' => ['deadline_digest' => ['email' => false]]])->save();
        $this->assertFalse(NotificationTypes::wants($this->owner->fresh(), 'deadline_digest', 'email'));
    }
}
