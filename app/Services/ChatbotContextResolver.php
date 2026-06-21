<?php

namespace App\Services;

use App\Models\ChatbotSettings;
use App\Models\Client;
use App\Models\Dossier;

/**
 * Détecte si la question utilisateur référence un dossier ou un client précis.
 *
 * Retourne :
 *  - string : résumé à injecter dans le system prompt (cas trouvé unique)
 *  - array  : ['disambiguation' => [...]] quand plusieurs candidats
 *  - null   : rien à injecter
 *
 * Patterns détectés :
 *  - "dossier 42", "dossier #42", "dossier n°42"
 *  - "client 17", "client #17"
 *  - "client <nom prénom>" (ex : "client Jean Dupont", "résume le client Marie")
 *  - "dossier de <nom>" (ex : "le dossier de Jean Dupont")
 */
class ChatbotContextResolver
{
    public function resolve(string $userMessage, ChatbotSettings $settings): array|string|null
    {
        // 1) Détection par ID (prioritaire)
        if ($settings->allow_dossier_lookup) {
            if (preg_match('/\bdossier\s*(?:#|n[°o]\s*)?\s*(\d{1,8})\b/iu', $userMessage, $m)) {
                return $this->summarizeDossier((int) $m[1]);
            }
        }
        if ($settings->allow_client_lookup) {
            if (preg_match('/\bclient\s*(?:#|n[°o]\s*)?\s*(\d{1,8})\b/iu', $userMessage, $m)) {
                return $this->summarizeClient((int) $m[1]);
            }
        }

        // 2) Détection par nom (client)
        if ($settings->allow_client_lookup) {
            $name = $this->extractNameAfter($userMessage, ['client']);
            if ($name) {
                $matches = $this->searchClients($name);
                if (count($matches) === 0) {
                    return "### Client « {$name} »\nAucun client trouvé portant ce nom. Demande à l'utilisateur de préciser ou d'utiliser l'identifiant numérique.";
                }
                if (count($matches) === 1) {
                    return $this->summarizeClient($matches[0]->id);
                }
                return [
                    'disambiguation' => [
                        'type' => 'client',
                        'query' => $name,
                        'message' => "J'ai trouvé plusieurs clients correspondant à « {$name} ». Lequel souhaitez-vous ?",
                        'options' => array_map(function ($c) {
                            $label = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                            $extra = $c->email ?? $c->phone ?? '';
                            return [
                                'id' => $c->id,
                                'label' => $label . ($extra ? " — {$extra}" : ''),
                                'sendText' => "le client #{$c->id}",
                            ];
                        }, $matches),
                    ],
                ];
            }
        }

        // 3) Détection par nom de client dans un dossier ("dossier de X")
        if ($settings->allow_dossier_lookup) {
            $name = $this->extractNameAfter($userMessage, ['dossier de', 'dossier pour', 'dossier du', 'dossier de la']);
            if ($name) {
                $clients = $this->searchClients($name);
                if (count($clients) === 0) {
                    return "### Dossier de « {$name} »\nAucun client trouvé portant ce nom.";
                }
                // Pour chaque client matché, lister ses dossiers
                $allDossiers = [];
                foreach ($clients as $c) {
                    $ds = Dossier::where('client_id', $c->id)->get();
                    foreach ($ds as $d) {
                        $allDossiers[] = ['client' => $c, 'dossier' => $d];
                    }
                }
                if (count($allDossiers) === 0) {
                    return "### Dossier de « {$name} »\nLes clients trouvés n'ont aucun dossier ouvert.";
                }
                if (count($allDossiers) === 1) {
                    return $this->summarizeDossier($allDossiers[0]['dossier']->id);
                }
                return [
                    'disambiguation' => [
                        'type' => 'dossier',
                        'query' => $name,
                        'message' => "Plusieurs dossiers correspondent à « {$name} ». Lequel souhaitez-vous ?",
                        'options' => array_map(function ($entry) {
                            $c = $entry['client'];
                            $d = $entry['dossier'];
                            $clientName = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                            $service = $d->service_name ?? $d->immigration_service ?? 'Service ?';
                            return [
                                'id' => $d->id,
                                'label' => "Dossier #{$d->id} — {$clientName} ({$service})",
                                'sendText' => "le dossier #{$d->id}",
                            ];
                        }, $allDossiers),
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Extrait un candidat de nom après l'un des mots-clés.
     * Capture 1-4 mots suivant le mot-clé, arrête sur ponctuation.
     */
    private function extractNameAfter(string $text, array $keywords): ?string
    {
        foreach ($keywords as $kw) {
            $pattern = '/\b' . preg_quote($kw, '/') . '\s+([^.,!?;\n#]{2,60})/iu';
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim($m[1]);
                // Limiter à 1-4 mots
                $words = preg_split('/\s+/', $candidate);
                $words = array_slice($words, 0, 4);
                $candidate = trim(implode(' ', $words));
                // Filtrer les mots vides courants en fin (de, du, des, la, le, les, mon, ma, etc.)
                $stopwords = ['de', 'du', 'des', 'la', 'le', 'les', 'mon', 'ma', 'mes', 'ce', 'cette', 'ces', 'à', 'au', 'aux', 'pour', 'avec', 'sur', 'dans', 'qui', 'que', 'est', 'sont'];
                while (count($words) > 1 && in_array(strtolower(end($words)), $stopwords, true)) {
                    array_pop($words);
                }
                $candidate = trim(implode(' ', $words));
                // Doit faire au moins 2 caractères et ne pas être uniquement numérique
                if (mb_strlen($candidate) < 2 || ctype_digit($candidate)) return null;
                // Skip si c'est manifestement une question ("résume", "donne", etc.)
                $firstWord = strtolower($words[0] ?? '');
                $skipFirst = ['résume', 'resume', 'donne', 'parle', 'dis', 'montre', 'affiche'];
                if (in_array($firstWord, $skipFirst, true)) return null;
                return $candidate;
            }
        }
        return null;
    }

    private function searchClients(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [];
        try {
            $like = '%' . str_replace(' ', '%', $name) . '%';
            return Client::where(function ($q) use ($name, $like) {
                $q->where('first_name', 'LIKE', '%' . $name . '%')
                  ->orWhere('last_name', 'LIKE', '%' . $name . '%')
                  ->orWhereRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ?", [$like])
                  ->orWhereRaw("CONCAT(COALESCE(last_name,''),' ',COALESCE(first_name,'')) LIKE ?", [$like]);
            })->limit(8)->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function summarizeDossier(int $id): ?string
    {
        try {
            $d = Dossier::with(['client', 'collaborator', 'documents'])->find($id);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$d) return "### Dossier #{$id}\nIntrouvable dans la base.";

        $client = $d->client ? trim(($d->client->first_name ?? '') . ' ' . ($d->client->last_name ?? '')) : 'Client inconnu';
        $service = $d->service_name ?? $d->immigration_service ?? 'Non spécifié';
        $collab = $d->collaborator ? ($d->collaborator->name ?? $d->collaborator->email ?? '—') : 'Aucun';
        $status = $d->status ?? 'inconnu';

        $docs = collect($d->documents ?? [])->map(function ($doc) {
            $name = $doc->name ?? $doc->title ?? 'Document';
            $type = strtoupper($doc->doc_type ?? '—');
            $st = $doc->status ?? 'inconnu';
            $by = $doc->filled_by ?? '—';
            return "  - {$name} [{$type}] · statut: {$st} · dernier remplissage: {$by}";
        })->take(20)->implode("\n");

        $notes = $d->notes ? "\nNotes admin : " . substr(trim(strip_tags($d->notes)), 0, 300) : '';
        $createdAt = $d->created_at ? $d->created_at->format('Y-m-d') : '—';

        return "### Dossier #{$id}\n"
            . "- Client : {$client}\n"
            . "- Service d'immigration : {$service}\n"
            . "- Statut : {$status}\n"
            . "- Collaborateur assigné : {$collab}\n"
            . "- Créé le : {$createdAt}\n"
            . "- Documents :\n" . ($docs ?: '  (aucun)')
            . $notes;
    }

    private function summarizeClient(int $id): ?string
    {
        try {
            $c = Client::with(['familyMembers', 'dossiers'])->find($id);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$c) return "### Client #{$id}\nIntrouvable dans la base.";

        $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: '—';
        $email = $c->email ?? '—';
        $phone = $c->phone ?? '—';
        $location = $c->location ?? $c->country ?? '—';

        $family = collect($c->familyMembers ?? [])->map(function ($fm) {
            return "  - " . trim(($fm->first_name ?? '') . ' ' . ($fm->last_name ?? '')) . " (" . ($fm->relationship ?? '?') . ")";
        })->implode("\n");

        $dossiers = collect($c->dossiers ?? [])->map(function ($d) {
            return "  - Dossier #{$d->id} — " . ($d->service_name ?? $d->immigration_service ?? '?') . " · " . ($d->status ?? '?');
        })->implode("\n");

        return "### Client #{$id}\n"
            . "- Nom : {$name}\n"
            . "- Email : {$email}\n"
            . "- Téléphone : {$phone}\n"
            . "- Localisation : {$location}\n"
            . "- Membres famille :\n" . ($family ?: '  (aucun)') . "\n"
            . "- Dossiers :\n" . ($dossiers ?: '  (aucun)');
    }
}
