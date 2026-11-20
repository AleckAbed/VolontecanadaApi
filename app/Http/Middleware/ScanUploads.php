<?php

namespace App\Http\Middleware;

use App\Support\Security\ScannerUnavailable;
use App\Support\Security\VirusScanner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Analyse antivirus de TOUT fichier reçu par l'API, avant le contrôleur :
 *  - les fichiers téléversés (multipart), à n'importe quelle profondeur ;
 *  - les fichiers envoyés en base64 (champs « *_base64 », ex. PDF rempli dans le visualiseur).
 *
 * Fichier infecté : refusé (422), et la tentative est journalisée sans contenu.
 * Antivirus injoignable : refusé (503) si CLAMAV_REQUIRED, sinon accepté avec un avertissement.
 */
class ScanUploads
{
    public function __construct(private VirusScanner $scanner) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->scanner->enabled() && ! config('security.antivirus.required')) {
            return $next($request);
        }

        try {
            foreach ($this->uploadedFiles($request->allFiles()) as $file) {
                if ($virus = $this->scanner->scanFile($file->getRealPath())) {
                    return $this->infected($request, $file->getClientOriginalName(), $virus);
                }
            }
            foreach ($this->base64Fields($request->all()) as $field => $encoded) {
                $bytes = base64_decode($encoded, true);
                if ($bytes !== false && ($virus = $this->scanner->scanBytes($bytes))) {
                    return $this->infected($request, $field, $virus);
                }
            }
        } catch (ScannerUnavailable $e) {
            if (config('security.antivirus.required')) {
                Log::error('Antivirus indisponible : téléversement refusé.', ['path' => $request->path(), 'reason' => $e->getMessage()]);

                return response()->json([
                    'success' => false,
                    'message' => 'Le fichier n’a pas pu être vérifié par l’antivirus. Réessayez dans quelques minutes.',
                ], 503);
            }
            Log::warning('Antivirus indisponible : fichier accepté sans analyse.', ['path' => $request->path(), 'reason' => $e->getMessage()]);
        }

        return $next($request);
    }

    /** @return UploadedFile[] */
    private function uploadedFiles(array $files): array
    {
        $out = [];
        array_walk_recursive($files, function ($f) use (&$out) {
            if ($f instanceof UploadedFile && $f->isValid()) {
                $out[] = $f;
            }
        });

        return $out;
    }

    /** @return array<string, string> */
    private function base64Fields(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_base64') && is_string($value) && strlen($value) > 64) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function infected(Request $request, string $name, string $virus): Response
    {
        Log::warning('Fichier infecté refusé.', ['path' => $request->path(), 'file' => $name, 'virus' => $virus, 'ip' => $request->ip()]);

        return response()->json([
            'success' => false,
            'message' => 'Ce fichier a été refusé : l’antivirus y a détecté une menace. Vérifiez votre appareil, puis envoyez un autre fichier.',
        ], 422);
    }
}
