<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogTemplate;
use App\Models\DocumentTemplate;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalogue de modèles côté cabinet : formulaires prêts à l'emploi (préremplissage déjà
 * relié), importés en un clic et mis à jour quand la plateforme publie une nouvelle version.
 */
class CatalogController extends Controller
{
    public function index()
    {
        $imported = DocumentTemplate::whereNotNull('catalog_template_id')->get()->keyBy('catalog_template_id');

        return response()->json(['success' => true, 'data' => CatalogTemplate::where('is_published', true)->orderBy('name')->get()
            ->map(function (CatalogTemplate $t) use ($imported) {
                $mine = $imported->get($t->id);

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'doc_type' => $t->doc_type,
                    'fields' => $t->fieldCount(),
                    'version' => $t->version,
                    'imported_template_id' => $mine?->id,
                    'update_available' => $mine && (int) $mine->catalog_version < $t->version,
                ];
            })]);
    }

    /** Importe le modèle (ou le met à jour s'il a déjà été importé : PDF et correspondance remplacés). */
    public function import(Request $request, int $id)
    {
        $catalog = CatalogTemplate::where('is_published', true)->findOrFail($id);
        if (! Documents::disk()->exists($catalog->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'Le PDF de ce modèle est indisponible. Contactez le support.'], 422);
        }

        $path = CurrentCabinet::storagePath('document-templates/' . Str::random(24) . '.pdf');
        Documents::disk()->put($path, Documents::disk()->get($catalog->pdf_path));

        $existing = DocumentTemplate::where('catalog_template_id', $catalog->id)->first();
        if ($existing) {
            $old = $existing->pdf_path;
            $existing->forceFill(['pdf_path' => $path, 'field_map' => $catalog->field_map, 'catalog_version' => $catalog->version])->save();
            Documents::deleteMany([$old]);

            return response()->json(['success' => true, 'message' => "« {$existing->name} » mis à jour (version {$catalog->version}). Les documents déjà envoyés ne changent pas.", 'data' => ['id' => $existing->id]]);
        }

        $template = DocumentTemplate::create([
            'name' => $catalog->name,
            'description' => $catalog->description,
            'service_name' => $catalog->service_name,
            'target_location' => $catalog->target_location,
            'doc_type' => $catalog->doc_type,
            'category' => $catalog->category,
            'is_active' => true,
            'pdf_path' => $path,
            'template_json' => null,
            'created_by' => $request->user()->id,
            'catalog_template_id' => $catalog->id,
            'catalog_version' => $catalog->version,
        ]);
        $template->forceFill(['field_map' => $catalog->field_map])->save();
        DB::table('catalog_templates')->where('id', $catalog->id)->increment('imports_count');

        return response()->json(['success' => true, 'message' => "« {$template->name} » ajouté à vos modèles, préremplissage compris.", 'data' => ['id' => $template->id]], 201);
    }
}
