<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copie les fichiers existants du disque local vers le stockage de production (2.5),
 * avant de passer DOCUMENTS_DISK=documents_s3. Reprend là où elle s'est arrêtée :
 * un fichier déjà présent avec la même taille n'est pas recopié. Ne supprime rien.
 */
class MigrateDocuments extends Command
{
    protected $signature = 'documents:migrate {--from=local} {--to=documents_s3} {--dry-run}';

    protected $description = 'Copie les documents d’un disque à l’autre (local vers stockage objet)';

    public function handle(): int
    {
        $from = Storage::disk($this->option('from'));
        $to = Storage::disk($this->option('to'));
        $copied = $skipped = 0;

        foreach ($from->allFiles() as $path) {
            if (str_starts_with(basename($path), '.')) {
                continue;
            }
            if ($to->exists($path) && $to->size($path) === $from->size($path)) {
                $skipped++;
                continue;
            }
            if (! $this->option('dry-run')) {
                $stream = $from->readStream($path);
                $to->writeStream($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $copied++;
        }

        $this->info(($this->option('dry-run') ? 'À copier' : 'Copiés') . " : {$copied} — déjà présents : {$skipped}.");

        return self::SUCCESS;
    }
}
