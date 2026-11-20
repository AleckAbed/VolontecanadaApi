<?php

namespace App\Support\Security;

/**
 * Analyse antivirus par ClamAV (démon clamd, protocole INSTREAM sur TCP).
 *
 * Sans CLAMAV_HOST, l'analyse est désactivée (développement) : scan() renvoie null.
 * Une erreur de connexion lève ScannerUnavailable ; c'est l'appelant qui décide de refuser.
 */
class VirusScanner
{
    public function enabled(): bool
    {
        return (string) config('security.antivirus.host') !== '';
    }

    /**
     * @return string|null le nom du virus trouvé, ou null si le fichier est sain ou l'analyse désactivée
     * @throws ScannerUnavailable
     */
    public function scanFile(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new ScannerUnavailable('Fichier illisible pour l’analyse.');
        }
        try {
            return $this->scanStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @throws ScannerUnavailable */
    public function scanBytes(string $bytes): ?string
    {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);
        try {
            return $this->scanStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @throws ScannerUnavailable
     */
    protected function scanStream($handle): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $host = (string) config('security.antivirus.host');
        $port = (int) config('security.antivirus.port', 3310);
        $timeout = (int) config('security.antivirus.timeout', 30);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if (! $socket) {
            throw new ScannerUnavailable("Antivirus injoignable ({$errstr}).");
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->write($socket, "zINSTREAM\0");
            $sent = 0;
            $max = (int) config('security.antivirus.max_bytes');
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $sent += strlen($chunk);
                if ($sent > $max) {
                    throw new ScannerUnavailable('Fichier trop volumineux pour l’analyse antivirus.');
                }
                $this->write($socket, pack('N', strlen($chunk)) . $chunk);
            }
            $this->write($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        // Réponses : « stream: OK », « stream: Eicar-Signature FOUND », « INSTREAM size limit exceeded. ERROR ».
        if (str_ends_with($reply, 'OK')) {
            return null;
        }
        if (str_ends_with($reply, 'FOUND')) {
            return trim(substr($reply, strpos($reply, ':') + 1, -strlen('FOUND')));
        }
        throw new ScannerUnavailable('Réponse inattendue de l’antivirus : ' . ($reply ?: 'vide'));
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) === false) {
            throw new ScannerUnavailable('Envoi à l’antivirus interrompu.');
        }
    }
}
