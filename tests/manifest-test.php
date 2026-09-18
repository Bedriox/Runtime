<?php

declare(strict_types=1);

require_once __DIR__ . '/../tools/ManifestModel.php';

$root = dirname(__DIR__);
$failures = [];

try {
    $manifest = ManifestModel::load($root . '/manifests/dependencies.json', $root);
    if ($manifest->sourceField('php', 'version') !== '8.4.25') {
        $failures[] = 'PHP version lookup returned the wrong value.';
    }
    if ($manifest->sourceField('leveldb', 'commit') !== 'aba9e148149bda96c5212905c67275a53e701b1c') {
        $failures[] = 'LevelDB commit lookup returned the wrong value.';
    }
    if ($manifest->sourceField('php-sdk-binary-tools', 'archiveType') !== 'zip') {
        $failures[] = 'Archive type lookup returned the wrong value.';
    }
    $windowsClosure = $manifest->targetClosure('windows-x86_64');
    if (count($windowsClosure) !== 22 || !in_array('php-sdk-dep-libxml2', $windowsClosure, true)
        || !in_array('php-sdk-dep-libiconv', $windowsClosure, true)) {
        $failures[] = 'Windows dependency closure lookup returned the wrong value.';
    }
    if ($manifest->sourceField('php-sdk-dep-libcurl', 'targets') !== ['windows-x86_64']) {
        $failures[] = 'Windows package target lookup returned the wrong value.';
    }
    foreach (['zlib', 'gmp', 'curl', 'openssl', 'libzip', 'sqlite', 'zstd'] as $sourceId) {
        $hash = $manifest->sourceField($sourceId, 'sha256');
        if (!is_string($hash) || strlen($hash) !== 64) {
            $failures[] = sprintf('Required native source %s has no SHA-256.', $sourceId);
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'Reviewed manifest failed: ' . $exception->getMessage();
}

foreach (['unknown-root-field.json', 'malformed.json'] as $fixture) {
    try {
        ManifestModel::load(__DIR__ . '/fixtures/' . $fixture, $root);
        $failures[] = sprintf('Invalid fixture %s was accepted.', $fixture);
    } catch (Throwable) {
        // Expected fail-closed behavior.
    }
}

foreach ([
    ['unknown-source', 'url'],
    ['php', 'unknown-field'],
    ['php', 'commit'],
] as [$id, $field]) {
    try {
        $manifest->sourceField($id, $field);
        $failures[] = sprintf('Invalid lookup %s/%s was accepted.', $id, $field);
    } catch (Throwable) {
        // Expected fail-closed behavior.
    }
}

try {
    $manifest->targetClosure('windows-arm64');
    $failures[] = 'An unreviewed Windows ARM64 closure was accepted.';
} catch (Throwable) {
    // Expected fail-closed behavior.
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'Test failure: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Manifest tests passed.\n";
