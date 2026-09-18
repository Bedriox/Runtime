<?php

declare(strict_types=1);

require_once __DIR__ . '/ManifestModel.php';

$root = dirname(__DIR__);
$errors = [];
$dependencyManifest = null;

try {
    $dependencyManifest = ManifestModel::load($root . '/manifests/dependencies.json', $root);
} catch (Throwable $exception) {
    $errors[] = $exception->getMessage();
}

$requiredFiles = [
    '.gitattributes', '.gitignore', 'AGENTS.md', 'CHANGELOG.md', 'CODE_OF_CONDUCT.md',
    'CONTRIBUTING.md', 'LICENSE', 'NOTICE', 'README.md', 'SECURITY.md',
    'THIRD_PARTY_NOTICES.md', 'compile.bat', 'compile.ps1', 'compile.sh',
    'config/php.ini-production', 'fixtures/metadata.json',
    'fixtures/corpus/leveldb-corpus-v1.bin',
    'fixtures/data/leveldb-none-v1.tar',
    'fixtures/data/leveldb-raw-zlib-v1.tar',
    'fixtures/data/leveldb-zstd-v1.tar',
    'fixtures/generators/build-corpus.php',
    'fixtures/generators/DeterministicUstar.php',
    'fixtures/generators/rebuild.php',
    'fixtures/generators/cpp/CMakeLists.txt',
    'fixtures/generators/cpp/main.cc',
    'fixtures/generators/go/go.mod', 'fixtures/generators/go/main.go',
    'fixtures/generators/patches/google-leveldb-zstd-tag.patch',
    'docs/architecture.md', 'docs/building.md', 'docs/releasing.md',
    'docs/testing.md', 'docs/troubleshooting.md',
    'scripts/unix/build.sh', 'scripts/unix/common.sh',
    'scripts/unix/compile-native.sh', 'scripts/unix/download-sources.sh',
    'scripts/unix/extract-sources.sh', 'scripts/unix/preflight.sh',
    'scripts/windows/build.ps1', 'scripts/windows/native-build.cmd',
    'scripts/windows/native-build.ps1', 'scripts/windows/RuntimeBuild.psm1',
    'scripts/windows/stage-runtime.ps1',
    'licenses/df-mc-goleveldb-BSD-2-Clause.txt',
    'licenses/google-leveldb-BSD-3-Clause.txt',
    'licenses/microsoft-vc143-runtime-NOTICE.txt',
    'tests/helpers/leveldb-fixture-probe.php',
    'tests/helpers/leveldb-sst-inspect-probe.php',
    'tests/leveldb-fixture-test.php',
    'tests/manifest-cli-test.php', 'tests/manifest-test.php',
    'tests/package-archive-test.php', 'tests/package-test.php', 'tests/qualify.php',
    'tests/unix-build-contract-test.php',
    'tests/windows-build-contract-test.php',
    'tools/LevelDbFixtureCorpus.php', 'tools/LevelDbTableInspector.php',
    'tools/UstarArchive.php',
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

$levelDbFixtureTest = $root . '/tests/leveldb-fixture-test.php';
if (is_file($levelDbFixtureTest)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($levelDbFixtureTest), $levelDbFixtureStatus);
    if ($levelDbFixtureStatus !== 0) {
        $errors[] = 'Independent LevelDB fixture tests failed.';
    }
}

$fixturesPath = $root . '/fixtures/metadata.json';
if (is_file($fixturesPath) && ($fixturesJson = file_get_contents($fixturesPath)) !== false) {
    try {
        $fixtures = json_decode($fixturesJson, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($fixtures) || array_keys($fixtures) !== ['schemaVersion', 'corpus', 'sources', 'generators', 'fixtures']) {
            throw new RuntimeException('Fixture metadata contains missing or unknown fields.');
        }
        if (
            $fixtures['schemaVersion'] !== 2
            || !is_array($fixtures['corpus'])
            || !is_array($fixtures['sources']) || !array_is_list($fixtures['sources'])
            || !is_array($fixtures['generators']) || !array_is_list($fixtures['generators'])
            || !is_array($fixtures['fixtures']) || !array_is_list($fixtures['fixtures'])
        ) {
            throw new RuntimeException('Fixture metadata has an unsupported schema.');
        }
        if (array_keys($fixtures['corpus']) !== ['path', 'sha256', 'bytes', 'format', 'entries']) {
            throw new RuntimeException('Fixture corpus contains missing or unknown fields.');
        }
        $corpus = $fixtures['corpus'];
        if (
            !is_string($corpus['path'])
            || preg_match('#^corpus/[A-Za-z0-9._/-]+$#D', $corpus['path']) !== 1
            || str_contains($corpus['path'], '..')
            || !is_string($corpus['sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $corpus['sha256']) !== 1
            || !is_int($corpus['bytes']) || $corpus['bytes'] < 1 || $corpus['bytes'] > 100_000_000
            || $corpus['format'] !== 'bedriox-length-prefixed-binary-v1'
            || !is_int($corpus['entries']) || $corpus['entries'] < 1 || $corpus['entries'] > 100_000
        ) {
            throw new RuntimeException('Fixture corpus metadata is invalid.');
        }
        $corpusFile = $root . '/fixtures/' . $corpus['path'];
        if (!is_file($corpusFile) || filesize($corpusFile) !== $corpus['bytes'] || hash_file('sha256', $corpusFile) !== $corpus['sha256']) {
            throw new RuntimeException('Fixture corpus identity differs from metadata.');
        }

        $sourceIds = [];
        foreach ($fixtures['sources'] as $source) {
            if (!is_array($source) || array_keys($source) !== ['id', 'repository', 'commit', 'url', 'archiveSha256', 'archiveBytes', 'license', 'licensePath', 'role', 'modifications']) {
                throw new RuntimeException('Fixture source contains missing or unknown fields.');
            }
            if (
                !is_string($source['id']) || preg_match('/^[a-z0-9-]+$/D', $source['id']) !== 1
                || isset($sourceIds[$source['id']])
                || !is_string($source['repository']) || !str_starts_with($source['repository'], 'https://github.com/')
                || !is_string($source['url']) || !str_starts_with($source['url'], 'https://')
                || !is_string($source['commit']) || preg_match('/^[0-9a-f]{40}$/D', $source['commit']) !== 1
                || !is_string($source['archiveSha256']) || preg_match('/^[0-9a-f]{64}$/D', $source['archiveSha256']) !== 1
                || !is_int($source['archiveBytes']) || $source['archiveBytes'] < 1
                || !is_string($source['license']) || $source['license'] === ''
                || !is_string($source['licensePath']) || preg_match('#^\.\./licenses/[A-Za-z0-9._-]+$#D', $source['licensePath']) !== 1
                || !is_file($root . '/fixtures/' . $source['licensePath'])
                || !is_string($source['role']) || $source['role'] === ''
                || !is_array($source['modifications']) || !array_is_list($source['modifications'])
            ) {
                throw new RuntimeException('Fixture source metadata is invalid.');
            }
            foreach ($source['modifications'] as $modification) {
                if (
                    !is_array($modification)
                    || array_keys($modification) !== ['path', 'sha256', 'description']
                    || !is_string($modification['path'])
                    || preg_match('#^generators/patches/[A-Za-z0-9._/-]+$#D', $modification['path']) !== 1
                    || str_contains($modification['path'], '..')
                    || !is_string($modification['sha256'])
                    || preg_match('/^[0-9a-f]{64}$/D', $modification['sha256']) !== 1
                    || !is_string($modification['description']) || $modification['description'] === ''
                ) {
                    throw new RuntimeException('Fixture source modification metadata is invalid.');
                }
                $modificationFile = $root . '/fixtures/' . $modification['path'];
                if (!is_file($modificationFile) || hash_file('sha256', $modificationFile) !== $modification['sha256']) {
                    throw new RuntimeException('Fixture source modification identity differs from metadata.');
                }
            }
            $sourceIds[$source['id']] = true;
        }

        foreach ($fixtures['generators'] as $generator) {
            if (!is_array($generator) || array_keys($generator) !== ['id', 'paths', 'sha256', 'source', 'inputs', 'commands', 'toolchain', 'toolchainArchive']) {
                throw new RuntimeException('Fixture generator contains missing or unknown fields.');
            }
            if (
                !is_string($generator['id']) || preg_match('/^[a-z0-9-]+$/D', $generator['id']) !== 1
                || !is_array($generator['paths']) || !array_is_list($generator['paths']) || $generator['paths'] === []
                || !is_array($generator['sha256']) || !array_is_list($generator['sha256'])
                || count($generator['paths']) !== count($generator['sha256'])
                || !is_string($generator['source']) || ($generator['source'] !== 'bedriox-runtime' && !isset($sourceIds[$generator['source']]))
                || !is_array($generator['inputs']) || !array_is_list($generator['inputs'])
                || !is_array($generator['commands']) || !array_is_list($generator['commands']) || $generator['commands'] === []
                || !is_string($generator['toolchain']) || $generator['toolchain'] === ''
            ) {
                throw new RuntimeException('Fixture generator metadata is invalid.');
            }
            foreach ($generator['paths'] as $index => $path) {
                if (
                    !is_string($path) || preg_match('#^generators/[A-Za-z0-9._/-]+$#D', $path) !== 1 || str_contains($path, '..')
                    || !is_string($generator['sha256'][$index]) || preg_match('/^[0-9a-f]{64}$/D', $generator['sha256'][$index]) !== 1
                    || !is_file($root . '/fixtures/' . $path)
                    || hash_file('sha256', $root . '/fixtures/' . $path) !== $generator['sha256'][$index]
                ) {
                    throw new RuntimeException('Fixture generator file identity is invalid.');
                }
            }
            foreach ($generator['inputs'] as $input) {
                if (
                    !is_array($input)
                    || array_keys($input) !== ['manifestSource', 'version', 'bytes', 'sha256']
                    || !is_string($input['manifestSource']) || $input['manifestSource'] === ''
                    || !is_string($input['version']) || $input['version'] === ''
                    || !is_int($input['bytes']) || $input['bytes'] < 1
                    || !is_string($input['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $input['sha256']) !== 1
                    || !$dependencyManifest instanceof ManifestModel
                    || $dependencyManifest->sourceField($input['manifestSource'], 'version') !== $input['version']
                    || $dependencyManifest->sourceField($input['manifestSource'], 'bytes') !== $input['bytes']
                    || $dependencyManifest->sourceField($input['manifestSource'], 'sha256') !== $input['sha256']
                ) {
                    throw new RuntimeException('Fixture generator dependency input differs from the immutable dependency manifest.');
                }
            }
            if ($generator['id'] === 'go-none-and-raw-deflate') {
                if (
                    $generator['inputs'] !== []
                    || $generator['toolchain'] !== 'go1.27.1 windows/amd64'
                    || $generator['toolchainArchive'] !== [
                        'url' => 'https://go.dev/dl/go1.27.1.windows-amd64.zip',
                        'bytes' => 78_931_360,
                        'sha256' => 'a3911b5e0e1b1053f25ed0675f4c1c6aad1e2bfcf253df2b9be4caabd2edd95d',
                    ]
                ) {
                    throw new RuntimeException('Go fixture generator toolchain provenance differs from the reviewed archive.');
                }
            } elseif ($generator['id'] === 'cpp-zstd') {
                if (
                    count($generator['inputs']) !== 1
                    || $generator['inputs'][0]['manifestSource'] !== 'zstd'
                    || $generator['toolchain'] !== 'CMake 4.3.1-msvc1; MSVC 19.29.30159.0'
                    || $generator['toolchainArchive'] !== null
                ) {
                    throw new RuntimeException('C++ fixture generator inputs or toolchain identity differ from the reviewed set.');
                }
            } elseif ($generator['id'] === 'corpus-and-archive-assembly') {
                if ($generator['inputs'] !== [] || $generator['toolchain'] !== 'PHP 8.4' || $generator['toolchainArchive'] !== null) {
                    throw new RuntimeException('Corpus fixture generator toolchain identity differs from the reviewed set.');
                }
            } else {
                throw new RuntimeException('Fixture generator set contains an unknown identifier.');
            }
        }

        foreach ($fixtures['fixtures'] as $fixture) {
            if (!is_array($fixture)) {
                throw new RuntimeException('Fixture entry must be an object.');
            }
            if (array_keys($fixture) !== ['id', 'path', 'sha256', 'bytes', 'producer', 'compressionTypes', 'format']) {
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
            if (
                !is_string($fixture['id']) || preg_match('/^[a-z0-9-]+$/D', $fixture['id']) !== 1
                || !is_string($fixture['producer']) || !isset($sourceIds[$fixture['producer']])
                || !is_array($fixture['compressionTypes']) || !array_is_list($fixture['compressionTypes']) || $fixture['compressionTypes'] === []
                || array_filter($fixture['compressionTypes'], static fn (mixed $type): bool => !is_int($type) || $type < 0 || $type > 255) !== []
                || $fixture['format'] !== 'ustar'
            ) {
                throw new RuntimeException('Fixture entry contains invalid provenance or format metadata.');
            }
            $fixtureFile = $root . '/fixtures/' . $fixture['path'];
            if (!is_file($fixtureFile) || filesize($fixtureFile) !== $fixture['bytes'] || hash_file('sha256', $fixtureFile) !== $fixture['sha256']) {
                throw new RuntimeException('Fixture file identity differs from metadata.');
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
