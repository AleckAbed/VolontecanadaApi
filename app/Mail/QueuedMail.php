<?php

namespace App\Mail;

use App\Models\Cabinet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base de tous les courriels de l'application (2.8).
 *
 * - Mail::to(...)->send(...) les met en FILE D'ATTENTE au lieu de les envoyer
 *   pendant la requête : la page répond tout de suite, et un serveur SMTP
 *   indisponible ne fait plus perdre le message (3 essais : maintenant, +1 min, +5 min).
 * - Le contenu de la tâche est CHIFFRÉ dans la table jobs (données personnelles,
 *   jetons de connexion).
 * - Envoyés seulement après la validation de la transaction en cours.
 *
 * En production, un worker doit tourner : php artisan queue:work (voir DEPLOIEMENT.md).
 * En développement, QUEUE_CONNECTION=sync envoie tout de suite, comme avant.
 *
 * Chaque vue reçoit $brand (1.9) : nom, logo et couleurs du cabinet concerné, trouvé
 * parmi les modèles du courriel (un Cabinet, ou tout modèle portant cabinet_id).
 */
abstract class QueuedMail extends Mailable implements ShouldQueueAfterCommit, ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> secondes avant le 2e puis le 3e essai */
    public array $backoff = [60, 300];

    public function buildViewData()
    {
        $data = parent::buildViewData();
        $data['brand'] = $this->resolveCabinet()?->branding() ?? [
            'name' => config('app.name'), 'logo_url' => null, 'color' => Cabinet::DEFAULT_COLOR,
            'color_dark' => Cabinet::darken(Cabinet::DEFAULT_COLOR), 'website' => null,
        ];

        return $data;
    }

    private function resolveCabinet(): ?Cabinet
    {
        $cabinetId = null;
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($this);
            if ($value instanceof Cabinet) {
                return $value;
            }
            if ($value instanceof \Illuminate\Database\Eloquent\Model && $value->getAttribute('cabinet_id')) {
                $cabinetId ??= (int) $value->getAttribute('cabinet_id');
            }
        }

        return $cabinetId ? Cabinet::find($cabinetId) : null;
    }
}
