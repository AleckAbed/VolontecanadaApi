<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Support\Documents;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Profil et image de marque du cabinet (1.9) : nom, coordonnées, logo, couleur.
 * Le logo est le seul fichier du cabinet servi publiquement (courriels, pages publiques).
 */
class CabinetProfileController extends Controller
{
    private const LOGO_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function show()
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->cabinet())]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:120',
            'phone' => 'nullable|string|max:40',
            'website' => 'nullable|url:https,http|max:200',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $cabinet = $this->cabinet();
        if (array_key_exists('brand_color', $data) && $data['brand_color']) {
            $data['brand_color'] = strtolower($data['brand_color']);
        }
        $cabinet->forceFill($data)->save();

        return response()->json(['success' => true, 'message' => 'Profil du cabinet enregistré.', 'data' => $this->payload($cabinet)]);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            // Pas de SVG : il peut contenir du script.
            'logo' => 'required|file|mimetypes:image/png,image/jpeg,image/webp|max:1024|dimensions:min_width=64,min_height=32,max_width=4000,max_height=4000',
        ]);
        $cabinet = $this->cabinet();
        $file = $request->file('logo');
        $ext = self::LOGO_MIMES[$file->getMimeType()] ?? 'png';

        $old = $cabinet->logo_path;
        $path = $file->storeAs("cabinets/{$cabinet->id}/branding", 'logo-' . Str::random(10) . ".{$ext}", Documents::name());
        $cabinet->forceFill(['logo_path' => $path])->save();
        if ($old && $old !== $path) {
            Documents::deleteMany([$old]);
        }

        return response()->json(['success' => true, 'message' => 'Logo enregistré.', 'data' => $this->payload($cabinet)]);
    }

    public function deleteLogo()
    {
        $cabinet = $this->cabinet();
        if ($cabinet->logo_path) {
            Documents::deleteMany([$cabinet->logo_path]);
            $cabinet->forceFill(['logo_path' => null])->save();
        }

        return response()->json(['success' => true, 'message' => 'Logo retiré.', 'data' => $this->payload($cabinet)]);
    }

    /** Logo public d'un cabinet actif (courriels, pages de réservation, factures…). */
    public function publicLogo(string $slug)
    {
        $cabinet = CurrentCabinet::withoutScope(fn () => Cabinet::where('slug', $slug)->where('is_active', true)->first());
        if (! $cabinet?->logo_path || ! Documents::disk()->exists($cabinet->logo_path)) {
            abort(404);
        }

        return Documents::inline($cabinet->logo_path, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /**
     * Image de marque d'un lien public (invitation, questionnaire, document, activation) :
     * le cabinet est résolu depuis le code par le middleware cabinet.code.
     */
    public function publicBranding()
    {
        $cabinet = CurrentCabinet::check() ? Cabinet::find(CurrentCabinet::id()) : null;
        if (! $cabinet) {
            abort(404);
        }

        return response()->json(['success' => true, 'data' => $cabinet->branding()]);
    }

    private function cabinet(): Cabinet
    {
        return Cabinet::findOrFail(CurrentCabinet::require());
    }

    private function payload(Cabinet $cabinet): array
    {
        return [
            'name' => $cabinet->name,
            'slug' => $cabinet->slug,
            'phone' => $cabinet->phone,
            'website' => $cabinet->website,
            'province' => $cabinet->province,
            'brand_color' => $cabinet->brand_color,
            'branding' => $cabinet->branding(),
        ];
    }
}
