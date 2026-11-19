<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\ClientProfileService;
use App\Support\ClientProfileFields;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Correspondance des champs d'un modèle PDF avec le profil client.
 *
 * Champs texte : l'admin tape un jeton (@nom, @passeport, @date_naissance:jj/mm/aaaa…)
 * dans le champ, puis enregistre. Cases à cocher, boutons radio et listes : il choisit
 * l'information (et la valeur), puis clique la case dans le PDF (voir addRules).
 * Les clés sont celles du visualiseur, donc identiques au moment du préremplissage,
 * que le PDF soit XFA (IRCC) ou AcroForm.
 *
 * Une entrée vaut soit « clé du profil », soit :
 *  - {key, format} : date mise en forme ;
 *  - {key, equals, set, g} : quand la valeur du profil vaut l'une de « equals », on injecte « set » ;
 *  - {key, choice, format?, g} : liste déroulante, l'option est choisie d'après la valeur ;
 *  - {key, click, format?, g} : champ texte relié en cliquant (champs trop courts pour un jeton).
 */
class FieldMapController extends Controller
{
    public function __construct(private ClientProfileService $profiles) {}

    public function show(int $id)
    {
        $template = DocumentTemplate::findOrFail($id);
        $map = $template->field_map ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'template' => ['id' => $template->id, 'name' => $template->name],
                'fields' => ClientProfileFields::definitions(),
                // À passer au visualiseur pour réafficher les jetons déjà posés.
                'token_values' => (object) $this->profiles->tokensForMap($map),
            ] + $this->summary($map),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $template = DocumentTemplate::findOrFail($id);
        $request->validate(['form_data' => 'present|array']);

        ['map' => $map, 'unknown' => $unknown] = $this->profiles->mapFromTokens($request->input('form_data', []));

        // Les cases et listes reliées en cliquant ne passent pas par les jetons : on les garde.
        $rules = array_filter($template->field_map ?? [], [ClientProfileService::class, 'isRule']);
        $map = $rules + $map;

        $template->field_map = $map ?: null;
        $template->save();

        $summary = $this->summary($map);
        $count = $summary['mapped_count'];

        return response()->json([
            'success' => true,
            'message' => $count === 0
                ? 'Aucun jeton reconnu : le modèle ne sera pas prérempli.'
                : "{$count} information(s) du profil reliée(s) à ce modèle.",
            'data' => $summary + ['unknown_tokens' => $unknown],
        ]);
    }

    /** Relie une case, un bouton radio ou une liste cliqué dans le PDF. */
    public function addRules(Request $request, int $id)
    {
        $template = DocumentTemplate::findOrFail($id);
        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(ClientProfileFields::keys())],
            'mode' => 'required|in:equals,choice,text',
            'equals' => 'required_if:mode,equals|array|max:10',
            'equals.*' => 'string|max:120',
            'format' => ['nullable', 'string', 'regex:' . ClientProfileFields::DATE_FORMAT],
            'targets' => 'required|array|min:1|max:4',
            'targets.*' => 'present',
        ], ['format.regex' => 'Format de date non reconnu (ex. jj/mm/aaaa, aaaa, mm, jj).']);

        foreach (array_keys($data['targets']) as $fieldKey) {
            if (! is_string($fieldKey) || $fieldKey === '' || strlen($fieldKey) > 500) {
                return response()->json(['success' => false, 'message' => 'Champ du PDF non reconnu.'], 422);
            }
        }
        if (strlen(json_encode($data['targets'])) > 4000) {
            return response()->json(['success' => false, 'message' => 'Valeur du champ trop longue.'], 422);
        }

        $group = Str::lower(Str::random(8));
        $base = ['key' => $data['key'], 'g' => $group] + (! empty($data['format']) ? ['format' => $data['format']] : []);
        $map = $template->field_map ?? [];
        foreach ($data['targets'] as $fieldKey => $set) {
            $map[$fieldKey] = match ($data['mode']) {
                'choice' => $base + ['choice' => true],
                'text' => $base + ['click' => true],
                default => $base + ['equals' => array_values(array_filter(array_map('trim', $data['equals']), 'strlen')), 'set' => $set],
            };
        }
        $template->field_map = $map;
        $template->save();

        return response()->json(['success' => true, 'message' => 'Champ relié.', 'data' => $this->summary($map)]);
    }

    public function removeRule(int $id, string $group)
    {
        $template = DocumentTemplate::findOrFail($id);
        $map = array_filter($template->field_map ?? [], fn ($e) => ! (ClientProfileService::isRule($e) && ($e['g'] ?? null) === $group));
        $template->field_map = $map ?: null;
        $template->save();

        return response()->json(['success' => true, 'message' => 'Lien retiré.', 'data' => $this->summary($map)]);
    }

    /** Informations reliées et liste des cases et listes reliées en cliquant (une ligne par clic). */
    private function summary(array $map): array
    {
        $keys = array_values(array_unique(array_filter(array_map([ClientProfileService::class, 'profileKeyOf'], $map))));
        $rules = [];
        foreach ($map as $fieldKey => $entry) {
            if (! ClientProfileService::isRule($entry)) {
                continue;
            }
            $g = $entry['g'] ?? $fieldKey;
            $rules[$g] ??= [
                'group' => $g,
                'key' => $entry['key'],
                'label' => ClientProfileFields::FIELDS[$entry['key']][1] ?? $entry['key'],
                'mode' => ! empty($entry['choice']) ? 'choice' : (isset($entry['equals']) ? 'equals' : 'text'),
                'equals' => $entry['equals'] ?? [],
                'format' => $entry['format'] ?? null,
                'fields' => [],
            ];
            $rules[$g]['fields'][] = $fieldKey;
        }

        return ['mapped_count' => count($keys), 'mapped_keys' => $keys, 'rules' => array_values($rules)];
    }
}
