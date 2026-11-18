<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\CatalogTemplate;
use App\Models\DocumentTemplate;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console plateforme : catalogue de modèles partagé.
 * On prépare un modèle dans un cabinet (PDF + correspondance des champs), puis on le
 * publie dans le catalogue ; les cabinets l'importent ensuite en un clic.
 */
class PlatformCatalogController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => CatalogTemplate::orderBy('name')->get()->map(fn (CatalogTemplate $t) => $this->format($t))]);
    }

    /** Modèles d'un cabinet qui peuvent servir de source (ceux qui ont une correspondance). */
    public function cabinetTemplates(int $cabinetId)
    {
        $cabinet = Cabinet::findOrFail($cabinetId);
        $templates = CurrentCabinet::withoutScope(fn () => DocumentTemplate::acrossCabinets()->where('cabinet_id', $cabinet->id)->orderBy('name')->get());

        return response()->json(['success' => true, 'data' => $templates->map(fn (DocumentTemplate $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'doc_type' => $t->doc_type,
            'fields' => count($t->field_map ?? []),
            'from_catalog' => (bool) $t->catalog_template_id,
        ])]);
    }

    /**
     * Copie un modèle de cabinet dans le catalogue (nouveau), ou remplace un modèle du
     * catalogue par la nouvelle version de ce modèle (version + 1).
     */
    public function publishFromCabinet(Request $request)
    {
        $data = $request->validate([
            'cabinet_id' => 'required|integer',
            'template_id' => 'required|integer',
            'catalog_template_id' => 'nullable|integer|exists:catalog_templates,id',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);
        $source = CurrentCabinet::withoutScope(fn () => DocumentTemplate::acrossCabinets()
            ->where('cabinet_id', $data['cabinet_id'])->findOrFail($data['template_id']));
        if (! $source->pdf_path || ! Documents::disk()->exists($source->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'Le PDF de ce modèle est introuvable.'], 422);
        }
        if (empty($source->field_map)) {
            return response()->json(['success' => false, 'message' => 'Reliez d’abord les champs de ce modèle au profil client (Préremplissage), puis publiez-le.'], 422);
        }

        $path = 'catalog/' . Str::random(24) . '.pdf';
        Documents::disk()->put($path, Documents::disk()->get($source->pdf_path));

        $existing = ! empty($data['catalog_template_id']) ? CatalogTemplate::findOrFail($data['catalog_template_id']) : null;
        $attrs = [
            'name' => $data['name'] ?? $existing?->name ?? $source->name,
            'description' => $data['description'] ?? $existing?->description ?? $source->description,
            'service_name' => $source->service_name,
            'target_location' => $source->target_location ?: 'any',
            'doc_type' => $source->doc_type ?: 'ircc',
            'category' => $source->category ?: 'ircc',
            'pdf_path' => $path,
            'field_map' => $source->field_map,
        ];
        if ($existing) {
            $old = $existing->pdf_path;
            $existing->forceFill($attrs + ['version' => $existing->version + 1])->save();
            Documents::deleteMany([$old]);
            $catalog = $existing;
        } else {
            $catalog = CatalogTemplate::create($attrs + ['created_by' => $request->user('platform')?->id, 'is_published' => false]);
        }

        return response()->json(['success' => true, 'message' => $existing ? "Nouvelle version {$catalog->version} enregistrée." : 'Modèle ajouté au catalogue (non publié).', 'data' => $this->format($catalog)], $existing ? 200 : 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_published' => 'sometimes|boolean',
        ]);
        $catalog = CatalogTemplate::findOrFail($id);
        $catalog->forceFill($data)->save();

        return response()->json(['success' => true, 'data' => $this->format($catalog)]);
    }

    public function destroy(int $id)
    {
        $catalog = CatalogTemplate::findOrFail($id);
        Documents::deleteMany([$catalog->pdf_path]);
        $catalog->delete(); // les modèles déjà importés par les cabinets restent (lien remis à vide)

        return response()->json(['success' => true, 'message' => 'Modèle retiré du catalogue. Les cabinets qui l’ont importé le gardent.']);
    }

    private function format(CatalogTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'doc_type' => $t->doc_type,
            'category' => $t->category,
            'fields' => $t->fieldCount(),
            'version' => $t->version,
            'is_published' => $t->is_published,
            'imports_count' => $t->imports_count,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
