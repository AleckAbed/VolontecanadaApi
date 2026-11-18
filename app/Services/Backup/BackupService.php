<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde chiffrée de la base (2.12).
 *
 * mysqldump → gzip → chiffrement (AES-256-GCM par blocs authentifiés)
 * → disque des sauvegardes (BACKUP_DISK : stockage objet au Canada en
 * production). Sans la clé BACKUP_ENCRYPTION_KEY, une sauvegarde est illisible :
 * la clé se garde HORS du serveur (gestionnaire de mots de passe du propriétaire).
 */
class BackupService
{
    private const CHUNK = 1048576; // 1 Mo

    private const MAGIC = "VCBK1\n"; // version du format

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.backups', 'backups_local'));
    }

    /** Crée une sauvegarde et renvoie son nom sur le disque des sauvegardes. */
    public function create(): string
    {
        $key = $this->key();
        $tmp = $this->tmpDir();
        $sql = "{$tmp}/dump.sql";
        $gz = "{$tmp}/dump.sql.gz";
        $enc = "{$tmp}/dump.sql.gz.enc";

        try {
            $this->dump($sql);
            $this->gzip($sql, $gz);
            @unlink($sql);
            $this->encrypt($gz, $enc, $key);
            @unlink($gz);

            $name = 'db/' . now('UTC')->format('Y/m/') . 'volonte-' . now('UTC')->format('Ymd-His') . '.sql.gz.enc';
            $stream = fopen($enc, 'rb');
            $this->disk()->writeStream($name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $name;
        } finally {
            $this->cleanup($tmp);
        }
    }

    /** Sauvegardes existantes, la plus récente en premier : [['name', 'size', 'time']]. */
    public function list(): array
    {
        $disk = $this->disk();

        return collect($disk->allFiles('db'))
            ->filter(fn ($f) => str_ends_with($f, '.sql.gz.enc'))
            ->map(fn ($f) => ['name' => $f, 'size' => $disk->size($f), 'time' => $disk->lastModified($f)])
            ->sortByDesc('time')
            ->values()
            ->all();
    }

    /** Supprime les sauvegardes plus vieilles que $days jours (en gardant toujours les 3 dernières). */
    public function prune(int $days): int
    {
        $limit = now()->subDays($days)->getTimestamp();
        $deleted = 0;
        foreach (array_slice($this->list(), 3) as $b) {
            if ($b['time'] < $limit) {
                $this->disk()->delete($b['name']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Déchiffre une sauvegarde vers un fichier .sql.gz local. */
    public function decryptTo(string $name, string $outGz): void
    {
        $tmp = $this->tmpDir();
        try {
            $enc = "{$tmp}/in.enc";
            $in = $this->disk()->readStream($name);
            if (! $in) {
                throw new RuntimeException("Sauvegarde introuvable : {$name}");
            }
            $out = fopen($enc, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
            $this->decrypt($enc, $outGz, $this->key());
        } finally {
            $this->cleanup($tmp);
        }
    }

    /**
     * Test de restauration sans toucher à la base : déchiffre, décompresse en entier
     * et vérifie que le contenu est un export complet.
     *
     * @return array{bytes:int, tables:int}
     */
    public function verify(string $name): array
    {
        $tmp = $this->tmpDir();
        try {
            $gz = "{$tmp}/verify.sql.gz";
            $this->decryptTo($name, $gz);
            $h = gzopen($gz, 'rb');
            if (! $h) {
                throw new RuntimeException('Archive gzip illisible.');
            }
            $bytes = 0;
            $tables = 0;
            $tail = '';
            $carry = '';
            while (! gzeof($h)) {
                $chunk = gzread($h, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('Archive gzip corrompue.');
                }
                $bytes += strlen($chunk);
                $buf = $carry . $chunk;
                $tables += substr_count($buf, 'CREATE TABLE `') - substr_count($carry, 'CREATE TABLE `');
                $carry = substr($buf, -64);
                $tail = substr($tail . $chunk, -512);
            }
            gzclose($h);

            if ($tables === 0 || ! str_contains($tail, 'Dump completed')) {
                throw new RuntimeException('Export incomplet : aucune table ou fin d’export absente.');
            }

            return ['bytes' => $bytes, 'tables' => $tables];
        } finally {
            $this->cleanup($tmp);
        }
    }

    /** Nouvelle clé de chiffrement, à mettre dans BACKUP_ENCRYPTION_KEY. */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    // ─── Interne ────────────────────────────────────────────────────────────

    private function key(): string
    {
        $raw = (string) config('backup.encryption_key');
        if ($raw === '') {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY manquante : générez-la avec « php artisan backup:key ».');
        }
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY invalide (attendu : base64:… de 32 octets).');
        }

        return $key;
    }

    private function dump(string $out): void
    {
        $c = config('database.connections.' . config('database.default'));
        if (! in_array($c['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Sauvegarde prévue pour MySQL ou MariaDB seulement.');
        }

        $process = new Process([
            config('backup.mysqldump', 'mysqldump'),
            '--host=' . $c['host'], '--port=' . $c['port'], '--user=' . $c['username'],
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
            '--default-character-set=utf8mb4', '--result-file=' . $out,
            $c['database'],
        ], null, ['MYSQL_PWD' => (string) $c['password']], null, 3600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            throw new RuntimeException('mysqldump a échoué : ' . trim($process->getErrorOutput()));
        }
    }

    private function gzip(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = gzopen($out, 'wb6');
        while (! feof($src)) {
            gzwrite($dst, fread($src, self::CHUNK));
        }
        fclose($src);
        gzclose($dst);
    }

    /*
     * Format : MAGIC, puis des blocs [longueur (4 o) | nonce (12 o) | tag (16 o) | chiffré].
     * AES-256-GCM ; les données associées (numéro du bloc + « dernier bloc ») empêchent
     * de réordonner, retirer ou tronquer des blocs sans que le déchiffrement échoue.
     */
    private function encrypt(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        fwrite($dst, self::MAGIC);
        $size = filesize($in);
        $read = 0;
        $index = 0;
        do {
            $chunk = (string) fread($src, self::CHUNK);
            $read += strlen($chunk);
            $last = $read >= $size;
            $nonce = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, pack('NC', $index++, $last ? 1 : 0), 16);
            if ($cipher === false) {
                throw new RuntimeException('Chiffrement impossible (OpenSSL).');
            }
            fwrite($dst, pack('N', strlen($cipher)) . $nonce . $tag . $cipher);
        } while (! $last);
        fclose($src);
        fclose($dst);
    }

    private function decrypt(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('Ce fichier n’est pas une sauvegarde reconnue.');
        }
        $dst = fopen($out, 'wb');
        $index = 0;
        $final = false;
        while (! $final && ($len = fread($src, 4)) !== '' && $len !== false) {
            $nonce = fread($src, 12);
            $tag = fread($src, 16);
            $n = unpack('N', $len)[1];
            $cipher = $n > 0 ? fread($src, $n) : '';
            $plain = false;
            foreach ([0, 1] as $isLast) {
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, pack('NC', $index, $isLast));
                if ($plain !== false) {
                    $final = $isLast === 1;
                    break;
                }
            }
            if ($plain === false) {
                throw new RuntimeException('Déchiffrement impossible : mauvaise clé ou fichier altéré.');
            }
            fwrite($dst, $plain);
            $index++;
        }
        fclose($src);
        fclose($dst);
        if (! $final) {
            throw new RuntimeException('Sauvegarde tronquée : la fin du fichier manque.');
        }
    }

    private function tmpDir(): string
    {
        $dir = storage_path('app/tmp/backup-' . bin2hex(random_bytes(6)));
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("Impossible de créer {$dir}");
        }

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
