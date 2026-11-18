<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\ClientProfileService;
use App\Support\ClientProfileFields;
use Illuminate\Http\Request;

/**
 * Correspondance des champs d'un modèle PDF avec le profil client.
 *
 * L'admin ouvre le PDF dans le visualiseur habituel, tape un jeton (@nom,
 * @passeport…) dans chaque champ à relier, puis enregistre : on garde la
 * correspondance « clé du champ → clé du profil ». Les clés sont celles du
 * visualiseur, donc identiques au moment du préremplissage, que le PDF soit
 * XFA (IRCC) ou AcroForm.
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
                'mapped_count' => count(array_unique(array_values($map))),
                'mapped_keys' => array_values(array_unique(array_values($map))),
                // À passer au visualiseur pour réafficher les jetons déjà posés.
                'token_values' => (object) $this->profiles->tokensForMap($map),
            ],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $template = DocumentTemplate::findOrFail($id);
        $request->validate(['form_data' => 'present|array']);

        ['map' => $map, 'unknown' => $unknown] = $this->profiles->mapFromTokens($request->input('form_data', []));

        $template->field_map = $map ?: null;
        $template->save();

        $count = count(array_unique(array_values($map)));

        return response()->json([
            'success' => true,
            'message' => $count === 0
                ? 'Aucun jeton reconnu : le modèle ne sera pas prérempli.'
                : "{$count} information(s) du profil reliée(s) à ce modèle.",
            'data' => [
                'mapped_count' => $count,
                'mapped_keys' => array_values(array_unique(array_values($map))),
                'unknown_tokens' => $unknown,
            ],
        ]);
    }
}
