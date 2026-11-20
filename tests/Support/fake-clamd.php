<?php

/**
 * Faux démon clamd pour les tests : répond au protocole INSTREAM comme ClamAV.
 * « FOUND » si le flux contient la chaîne de test EICAR, sinon « OK ». Une connexion par lancement
 * (ou plusieurs avec un 2e argument), puis s'arrête.
 *
 * Usage : php fake-clamd.php <port> [connexions]
 */
$port = (int) ($argv[1] ?? 3310);
$connections = (int) ($argv[2] ?? 1);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
fwrite(STDOUT, "ready\n");

for ($i = 0; $i < $connections; $i++) {
    $client = stream_socket_accept($server, 30);
    if (! $client) {
        break;
    }
    $command = '';
    while (! str_ends_with($command, "\0")) {
        $command .= fread($client, 1);
    }
    $data = '';
    while (true) {
        $len = unpack('N', fread($client, 4))[1];
        if ($len === 0) {
            break;
        }
        $chunk = '';
        while (strlen($chunk) < $len) {
            $chunk .= fread($client, $len - strlen($chunk));
        }
        $data .= $chunk;
    }
    $infected = str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE');
    fwrite($client, $infected ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
    fclose($client);
}
fclose($server);
