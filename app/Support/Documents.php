<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accès unique aux fichiers des clients et des cabinets (2.5).
 *
 * Le disque est choisi par DOCUMENTS_DISK : « local » en développement,
 * « documents_s3 » en production (stockage objet au Canada, chiffré).
 * Le code n'utilise jamais de chemin absolu sur le disque, pour fonctionner
 * pareil avec les deux.
 */
final class Documents
{
    public static function name(): string
    {
        return (string) config('filesystems.documents', 'local');
    }

    /** @return FilesystemAdapter */
    public static function disk(): Filesystem
    {
        return Storage::disk(self::name());
    }

    /** Sert un fichier dans le navigateur (aperçu PDF, image). */
    public static function inline(string $path, array $headers = []): StreamedResponse
    {
        return self::disk()->response($path, null, $headers);
    }

    /** Supprime des fichiers en ignorant les chemins vides ; renvoie le nombre supprimé. */
    public static function deleteMany(iterable $paths): int
    {
        $disk = self::disk();
        $count = 0;
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && $disk->exists($path)) {
                $disk->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
