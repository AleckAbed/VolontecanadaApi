<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Deadline;
use App\Models\Dossier;
use App\Models\FamilyMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Toutes les échéances du cabinet courant, de trois sources :
 *  - manual   : saisies par le cabinet (table deadlines) ;
 *  - dossier  : date limite (deadline_at) des dossiers actifs ;
 *  - profile  : expiration du passeport ou du permis/statut dans le profil client.
 *
 * Chaque échéance a une fenêtre de rappel (remind_days) : elle « demande une
 * action » dès qu'il reste au plus remind_days jours, et en retard ensuite.
 */
class DeadlineService
{
    /** Dossiers encore suivis : au-delà, leur date limite n'est plus pertinente. */
    public const ACTIVE_DOSSIER_STATUSES = ['en_cours', 'soumis'];

    private const PROFILE_EXPIRIES = [
        'passportExpiryDate' => ['passport_expiry', 'Expiration du passeport', 180],
        'permitExpiryDate' => ['permit_expiry', 'Expiration du permis ou du statut', 90],
    ];

    private const DOSSIER_REMIND_DAYS = 14;

    /** @return Collection<int, array> triées de la plus urgente à la plus lointaine */
    public function all(bool $includeDone = false): Collection
    {
        $today = Carbon::today();

        $items = collect();

        // 1. Échéances saisies
        $manual = Deadline::with(['dossier:id,name,client_id', 'client:id,first_name,last_name'])
            ->when(! $includeDone, fn ($q) => $q->whereNull('completed_at'))
            ->get();
        foreach ($manual as $d) {
            $client = $d->client ?? ($d->dossier ? Client::find($d->dossier->client_id) : null);
            $items->push($this->item(
                key: "manual:{$d->id}",
                source: 'manual',
                id: $d->id,
                type: $d->type,
                label: $d->label,
                due: $d->due_date,
                remindDays: $d->remind_days,
                dossier: $d->dossier,
                client: $client,
                today: $today,
                notes: $d->notes,
                done: $d->completed_at !== null,
            ));
        }

        // 2. Dates limites des dossiers actifs
        $dossiers = Dossier::whereNotNull('deadline_at')
            ->whereIn('status', self::ACTIVE_DOSSIER_STATUSES)
            ->get(['id', 'name', 'client_id', 'deadline_at']);
        $clients = Client::whereIn('id', $dossiers->pluck('client_id'))->get()->keyBy('id');
        foreach ($dossiers as $dossier) {
            $items->push($this->item(
                key: "dossier:{$dossier->id}",
                source: 'dossier',
                id: null,
                type: 'filing',
                label: 'Date limite du dossier',
                due: Carbon::parse($dossier->deadline_at),
                remindDays: self::DOSSIER_REMIND_DAYS,
                dossier: $dossier,
                client: $clients[$dossier->client_id] ?? null,
                today: $today,
            ));
        }

        // 3. Expirations tirées des profils clients
        $profiles = ClientProfile::all();
        $profileClients = Client::whereIn('id', $profiles->pluck('client_id'))->get()->keyBy('id');
        $members = FamilyMember::whereIn('id', $profiles->pluck('family_member_id')->filter())->get()->keyBy('id');
        foreach ($profiles as $profile) {
            $data = is_array($profile->data) ? $profile->data : [];
            $client = $profileClients[$profile->client_id] ?? null;
            $member = $profile->family_member_id ? ($members[$profile->family_member_id] ?? null) : null;
            $who = $member ? trim("{$member->first_name} {$member->last_name}") : ($client ? trim("{$client->first_name} {$client->last_name}") : '');

            foreach (self::PROFILE_EXPIRIES as $key => [$type, $label, $remind]) {
                if (empty($data[$key])) {
                    continue;
                }
                try {
                    $due = Carbon::parse($data[$key]);
                } catch (\Throwable) {
                    continue; // date mal saisie : ignorée plutôt que de casser le tableau
                }
                // On ne remonte que ce qui approche : au-delà de la fenêtre, rien à faire.
                if ($due->diffInDays($today, false) < -$remind) {
                    continue;
                }
                $items->push($this->item(
                    key: "profile:{$profile->id}:{$key}",
                    source: 'profile',
                    id: null,
                    type: $type,
                    label: $member ? "{$label} — {$who}" : $label,
                    due: $due,
                    remindDays: $remind,
                    dossier: null,
                    client: $client,
                    today: $today,
                ));
            }
        }

        return $items->sortBy('due_date')->values();
    }

    /** Échéances qui demandent une action : en retard ou dans leur fenêtre de rappel. */
    public function needingAction(): Collection
    {
        return $this->all()->filter(fn ($i) => in_array($i['urgency'], ['overdue', 'this_week', 'soon'], true))->values();
    }

    private function item(
        string $key,
        string $source,
        ?int $id,
        string $type,
        string $label,
        Carbon $due,
        int $remindDays,
        $dossier,
        ?Client $client,
        Carbon $today,
        ?string $notes = null,
        bool $done = false,
    ): array {
        $daysLeft = (int) $today->diffInDays($due->copy()->startOfDay(), false);

        $urgency = match (true) {
            $done => 'done',
            $daysLeft < 0 => 'overdue',
            $daysLeft <= 7 => 'this_week',
            $daysLeft <= $remindDays => 'soon',
            default => 'later',
        };

        return [
            'key' => $key,
            'source' => $source,
            'id' => $id,
            'type' => $type,
            'type_label' => Deadline::TYPES[$type] ?? $type,
            'label' => $label,
            'notes' => $notes,
            'due_date' => $due->toDateString(),
            'days_left' => $daysLeft,
            'remind_days' => $remindDays,
            'urgency' => $urgency,
            'dossier' => $dossier ? ['id' => $dossier->id, 'name' => $dossier->name] : null,
            'client' => $client ? ['id' => $client->id, 'name' => trim("{$client->first_name} {$client->last_name}")] : null,
            'link' => $dossier ? "/admin/dossiers/{$dossier->id}" : ($client ? "/admin/clients/{$client->id}" : null),
        ];
    }
}
