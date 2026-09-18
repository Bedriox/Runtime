<?php

declare(strict_types=1);

if ($argc !== 5 || !is_file($argv[1]) || !is_file($argv[2]) || !ctype_digit($argv[4])) {
    fwrite(STDERR, "Usage: local-tls-server.php <certificate> <private-key> <ready-file> <port>\n");
    exit(64);
}

$port = (int) $argv[4];
if ($port < 1 || $port > 65_535 || file_exists($argv[3])) {
    fwrite(STDERR, "Local TLS server arguments are invalid.\n");
    exit(64);
}

$context = stream_context_create(['ssl' => [
    'local_cert' => $argv[1],
    'local_pk' => $argv[2],
    'verify_peer' => false,
]]);
$errorCode = 0;
$errorMessage = '';
$server = @stream_socket_server(
    'tcp://127.0.0.1:' . $port,
    $errorCode,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if (!is_resource($server)) {
    fwrite(STDERR, 'Could not bind local TLS witness: ' . $errorCode . PHP_EOL);
    exit(1);
}

if (file_put_contents($argv[3], "ready\n", LOCK_EX) === false) {
    fclose($server);
    fwrite(STDERR, "Could not signal local TLS readiness.\n");
    exit(1);
}

for ($attempt = 0; $attempt < 2; ++$attempt) {
    $client = @stream_socket_accept($server, 10);
    if (!is_resource($client)) {
        fclose($server);
        fwrite(STDERR, "Local TLS witness did not receive both qualification connections.\n");
        exit(1);
    }
    stream_set_timeout($client, 5);
    @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    fclose($client);
}
fclose($server);
