<?php

declare(strict_types=1);

require_once __DIR__ . '/ManifestModel.php';

$root = dirname(__DIR__);
$errors = [];

try {
    ManifestModel::load($root . '/manifests/dependencies.json', $root);
} catch (Throwable $exception) {
    $errors[] = $exception->getMessage();
}

$requiredFiles = [
    '.gitignore', 'AGENTS.md', 'CHANGELOG.md', 'CODE_OF_CONDUCT.md',
    'CONTRIBUTING.md', 'LICENSE', 'NOTICE', 'README.md', 'SECURITY.md',
    'THIRD_PARTY_NOTICES.md', 'compile.bat', 'compile.ps1', 'compile.sh',
    'config/php.ini-production', 'fixtures/metadata.json',
    'docs/architecture.md', 'docs/building.md', 'docs/releasing.md',
    'docs/testing.md', 'docs/troubleshooting.md',
    'scripts/unix/build.sh', 'scripts/unix/common.sh',
    'scripts/unix/compile-native.sh', 'scripts/unix/download-sources.sh',
    'scripts/unix/extract-sources.sh', 'scripts/unix/preflight.sh',
    'scripts/windows/build.ps1', 'scripts/windows/native-build.cmd',
    'scripts/windows/native-build.ps1', 'scripts/windows/RuntimeBuild.psm1',
    'tests/manifest-cli-test.php', 'tests/manifest-test.php',
    'tests/package-archive-test.php', 'tests/package-test.php', 'tests/qualify.php',
    'tests/unix-build-contract-test.php',
    'tests/windows-build-contract-test.php',
    'tools/manifest.php', 'tools/ManifestModel.php', 'tools/package.php',
    'tools/package-archive.php', 'tools/PackageArchive.php',
    'tools/package-verify.php', 'tools/PackageBuilder.php',
    'tools/relocation-probe.php',
];
foreach ($requiredFiles as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) === 0) {
        $errors[] = sprintf('%s is missing or empty.', $relative);
    }
}

$markdownFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $entry) {
    $path = $entry->getPathname();
    $normalized = str_replace('\\', '/', $path);
    if (
        !$entry->isFile()
        || strtolower($entry->getExtension()) !== 'md'
        || preg_match('#/(?:\.git|\.build|\.cache|build|downloads|sources|artifacts|cache|tmp)/#', $normalized) === 1
    ) {
        continue;
    }
    $markdownFiles[] = $path;
}
foreach ($markdownFiles as $markdownPath) {
    $contents = file_get_contents($markdownPath);
    if ($contents === false) {
        $errors[] = 'Could not read Markdown file: ' . $markdownPath;
        continue;
    }
    preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $contents, $matches);
    foreach ($matches[1] as $link) {
        $link = trim($link, " <>\t\n\r\0\x0B");
        if ($link === '' || str_starts_with($link, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $link) === 1) {
            continue;
        }
        $relative = explode('#', $link, 2)[0];
        if ($relative === '' || str_contains($relative, '?')) {
            continue;
        }
        $target = dirname($markdownPath) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($relative));
        if (!file_exists($target)) {
            $errors[] = sprintf('Broken relative link in %s: %s', basename($markdownPath), $link);
        }
    }
}

$iniPath = $root . '/config/php.ini-production';
if (is_file($iniPath) && ($ini = file_get_contents($iniPath)) !== false) {
    foreach ([
        'extension_dir = "${BEDRIOX_RUNTIME_ROOT}/ext"',
        'openssl.cafile = "${BEDRIOX_RUNTIME_ROOT}/certs/cacert.pem"',
        'curl.cainfo = "${BEDRIOX_RUNTIME_ROOT}/certs/cacert.pem"',
        'phar.require_hash = On',
        '@BEDRIOX_DYNAMIC_EXTENSIONS@',
    ] as $required) {
        if (!str_contains($ini, $required)) {
            $errors[] = sprintf('PHP configuration is missing: %s', $required);
        }
    }
    if (preg_match('#(?:[A-Za-z]:[\\\\/]|/(?:Users|home|root)/)#i', $ini) === 1) {
        $errors[] = 'PHP configuration contains a private or absolute machine path.';
    }
}

$packageTest = $root . '/tests/package-test.php';
if (is_file($packageTest)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($packageTest), $packageStatus);
    if ($packageStatus !== 0) {
        $errors[] = 'Package builder tests failed.';
    }
}

$packageArchiveTest = $root . '/tests/package-archive-test.php';
if (is_file($packageArchiveTest)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($packageArchiveTest), $packageArchiveStatus);
    if ($packageArchiveStatus !== 0) {
        $errors[] = 'Package archive tests failed.';
    }
}

$unixBuildContractTest = $root . '/tests/unix-build-contract-test.php';
if (is_file($unixBuildContractTest)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($unixBuildContractTest), $unixBuildContractStatus);
    if ($unixBuildContractStatus !== 0) {
        $errors[] = 'Unix native build contract tests failed.';
    }
}

$windowsBuildContractTest = $root . '/tests/windows-build-contract-test.php';
if (is_file($windowsBuildContractTest)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($windowsBuildContractTest), $windowsBuildContractStatus);
    if ($windowsBuildContractStatus !== 0) {
        $errors[] = 'Windows native build contract tests failed.';
    }
}

$fixturesPath = $root . '/fixtures/metadata.json';
if (is_file($fixturesPath) && ($fixturesJson = file_get_contents($fixturesPath)) !== false) {
    try {
        $fixtures = json_decode($fixturesJson, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($fixtures) || array_keys($fixtures) !== ['schemaVersion', 'fixtures']) {
            throw new RuntimeException('Fixture metadata contains missing or unknown fields.');
        }
        if ($fixtures['schemaVersion'] !== 1 || !is_array($fixtures['fixtures']) || !array_is_list($fixtures['fixtures'])) {
            throw new RuntimeException('Fixture metadata has an unsupported schema.');
        }
        foreach ($fixtures['fixtures'] as $fixture) {
            if (!is_array($fixture)) {
                throw new RuntimeException('Fixture entry must be an object.');
            }
            $expected = ['id', 'path', 'sha256', 'bytes', 'license', 'source'];
            $actual = array_keys($fixture);
            sort($expected);
            sort($actual);
            if ($actual !== $expected) {
                throw new RuntimeException('Fixture entry contains missing or unknown fields.');
            }
            if (!is_string($fixture['path']) || preg_match('#^data/[A-Za-z0-9._/-]+$#D', $fixture['path']) !== 1 || str_contains($fixture['path'], '..')) {
                throw new RuntimeException('Fixture entry contains an unsafe path.');
            }
            if (!is_string($fixture['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $fixture['sha256']) !== 1) {
                throw new RuntimeException('Fixture entry contains an invalid SHA-256.');
            }
            if (!is_int($fixture['bytes']) || $fixture['bytes'] < 1 || $fixture['bytes'] > 100_000_000) {
                throw new RuntimeException('Fixture entry contains an invalid size.');
            }
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'Validation error: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "Runtime repository metadata is valid.\n";
