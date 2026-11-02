<?php

declare(strict_types=1);

$cli = dirname(__DIR__) . '/tools/manifest.php';
$failures = [];

/** @return array{int, string, string} */
function runManifestCli(string $cli, array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $cli, ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start manifest CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
}

$cases = [
    [['source', 'php', 'version'], 0, "8.4.25\n"],
    [['source', 'php', 'bytes'], 0, "13759432\n"],
    [['source', 'php-sdk-binary-tools', 'archiveType'], 0, "zip\n"],
    [['source', 'php-sdk-dep-libcurl', 'targets'], 0, "[\"windows-x86_64\"]\n"],
    [['closure', 'windows-x86_64'], 0, "[\"php\",\"leveldb\",\"php-leveldb\",\"libdatachannel\",\"libjuice\",\"plog\",\"usrsctp\",\"ext-webrtc\",\"ca-bundle\",\"php-sdk-binary-tools\",\"cmake-windows-x86_64\",\"php-sdk-dep-brotli\",\"php-sdk-dep-libbzip2\",\"php-sdk-dep-libcurl\",\"php-sdk-dep-libiconv\",\"php-sdk-dep-liblzma\",\"php-sdk-dep-libonig\",\"php-sdk-dep-libsodium\",\"php-sdk-dep-libssh2\",\"php-sdk-dep-libzip\",\"php-sdk-dep-libzstd\",\"php-sdk-dep-libxml2\",\"php-sdk-dep-mpir\",\"php-sdk-dep-nghttp2\",\"php-sdk-dep-openssl\",\"php-sdk-dep-sqlite3\",\"php-sdk-dep-zlib\"]\n"],
    [['targets'], 0, "[\"windows-x86_64\",\"linux-x86_64\",\"linux-arm64\",\"macos-x86_64\",\"macos-arm64\",\"windows-arm64\"]\n"],
];

foreach ($cases as [$arguments, $expectedStatus, $expectedOutput]) {
    [$status, $stdout, $stderr] = runManifestCli($cli, $arguments);
    $normalizedOutput = str_replace("\r\n", "\n", $stdout);
    if ($status !== $expectedStatus || $normalizedOutput !== $expectedOutput || $stderr !== '') {
        $failures[] = sprintf('CLI contract failed for: %s', implode(' ', $arguments));
    }
}

foreach ([
    [],
    ['targets', 'extra'],
    ['closure', 'windows-arm64'],
    ['source', 'missing', 'url'],
    ['source', 'php', 'missing'],
    ['source', 'php', 'commit'],
] as $arguments) {
    [$status, $stdout, $stderr] = runManifestCli($cli, $arguments);
    if ($status === 0 || $stdout !== '' || !str_starts_with($stderr, 'Manifest error: ')) {
        $failures[] = sprintf('CLI did not fail closed for: %s', implode(' ', $arguments));
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'Test failure: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Manifest CLI tests passed.\n";
