<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/LevelDbFixtureCorpus.php';
require_once dirname(__DIR__) . '/tools/LevelDbTableInspector.php';
require_once dirname(__DIR__) . '/tools/UstarArchive.php';
require_once dirname(__DIR__) . '/fixtures/generators/DeterministicUstar.php';

function fixtureEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{int, string} */
function fixtureProcess(array $command): array
{
    $escaped = array_map(escapeshellarg(...), $command);
    $output = [];
    exec(implode(' ', $escaped) . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
}

function fixtureRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

function fixtureRewriteUstarName(string $archive, int $headerOffset, string $name): string
{
    fixtureEnsure(strlen($name) <= 100, 'Test USTAR name is too long.');
    $archive = substr_replace($archive, str_pad($name, 100, "\0"), $headerOffset, 100);
    $archive = substr_replace($archive, '        ', $headerOffset + 148, 8);
    $header = substr($archive, $headerOffset, 512);
    $checksum = array_sum(unpack('C*', $header));
    return substr_replace($archive, sprintf("%06o\0 ", $checksum), $headerOffset + 148, 8);
}

/** @return list<int> */
function fixtureUstarHeaderOffsets(string $archive): array
{
    $offsets = [];
    $offset = 0;
    while (substr($archive, $offset, 512) !== str_repeat("\0", 512)) {
        $offsets[] = $offset;
        $sizeField = trim(substr($archive, $offset + 124, 12), " \0");
        fixtureEnsure($sizeField !== '' && preg_match('/^[0-7]+$/D', $sizeField) === 1, 'Test USTAR size field is invalid.');
        $size = intval($sizeField, 8);
        $offset += 512 + $size + ((512 - ($size % 512)) % 512);
    }
    return $offsets;
}

function fixtureExpectUstarRejected(string $path, string $message): void
{
    try {
        UstarArchive::read($path);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$metadataPath = $root . '/fixtures/metadata.json';
$metadata = json_decode((string) file_get_contents($metadataPath), true, 64, JSON_THROW_ON_ERROR);
fixtureEnsure(is_array($metadata) && array_keys($metadata) === ['schemaVersion', 'corpus', 'sources', 'generators', 'fixtures'], 'Fixture metadata has missing or unknown fields.');
fixtureEnsure($metadata['schemaVersion'] === 2, 'Fixture metadata schema is not version 2.');
fixtureEnsure(LevelDbTableInspector::crc32c('123456789') === 0xe3069283, 'CRC32C standard vector failed.');

$sourceIds = [];
foreach ($metadata['sources'] as $source) {
    fixtureEnsure(array_keys($source) === ['id', 'repository', 'commit', 'url', 'archiveSha256', 'archiveBytes', 'license', 'licensePath', 'role', 'modifications'], 'Fixture source has missing or unknown fields.');
    fixtureEnsure(!isset($sourceIds[$source['id']]), 'Fixture source identifier is duplicated.');
    fixtureEnsure(preg_match('/^[0-9a-f]{40}$/D', $source['commit']) === 1, 'Fixture source commit is invalid.');
    fixtureEnsure(preg_match('/^[0-9a-f]{64}$/D', $source['archiveSha256']) === 1 && $source['archiveBytes'] > 0, 'Fixture source archive identity is invalid.');
    fixtureEnsure(str_starts_with($source['repository'], 'https://github.com/') && str_starts_with($source['url'], 'https://'), 'Fixture source URL is not HTTPS.');
    fixtureEnsure(is_file($root . '/fixtures/' . $source['licensePath']), 'Fixture source license copy is missing.');
    foreach ($source['modifications'] as $modification) {
        fixtureEnsure(array_keys($modification) === ['path', 'sha256', 'description'], 'Fixture source modification has missing or unknown fields.');
        fixtureEnsure(is_string($modification['path']) && preg_match('#^generators/patches/[A-Za-z0-9._/-]+$#D', $modification['path']) === 1 && !str_contains($modification['path'], '..'), 'Fixture source modification path is unsafe.');
        fixtureEnsure(is_string($modification['sha256']) && preg_match('/^[0-9a-f]{64}$/D', $modification['sha256']) === 1, 'Fixture source modification digest is invalid.');
        fixtureEnsure(is_string($modification['description']) && $modification['description'] !== '', 'Fixture source modification description is empty.');
        $modificationPath = $root . '/fixtures/' . $modification['path'];
        fixtureEnsure(is_file($modificationPath) && hash_file('sha256', $modificationPath) === $modification['sha256'], 'Fixture source modification digest differs.');
    }
    $sourceIds[$source['id']] = true;
}
fixtureEnsure(array_keys($sourceIds) === ['df-mc-goleveldb', 'google-leveldb'], 'Fixture sources differ from the reviewed producer set.');

$generatorIds = [];
foreach ($metadata['generators'] as $generator) {
    fixtureEnsure(array_keys($generator) === ['id', 'paths', 'sha256', 'source', 'inputs', 'commands', 'toolchain', 'toolchainArchive'], 'Fixture generator has missing or unknown fields.');
    fixtureEnsure(!isset($generatorIds[$generator['id']]) && count($generator['paths']) === count($generator['sha256']), 'Fixture generator identity or file list is invalid.');
    foreach ($generator['paths'] as $index => $path) {
        $generatorPath = $root . '/fixtures/' . $path;
        fixtureEnsure(is_file($generatorPath) && hash_file('sha256', $generatorPath) === $generator['sha256'][$index], "Fixture generator digest differs: $path");
    }
    fixtureEnsure($generator['commands'] !== [] && $generator['toolchain'] !== '', 'Fixture generator rebuild instructions are incomplete.');
    fixtureEnsure(is_array($generator['inputs']) && array_is_list($generator['inputs']), 'Fixture generator inputs are invalid.');
    $generatorIds[$generator['id']] = true;
}
fixtureEnsure($metadata['generators'][0]['toolchain'] === 'go1.27.1 windows/amd64', 'Go fixture toolchain identity differs.');
fixtureEnsure($metadata['generators'][0]['toolchainArchive'] === [
    'url' => 'https://go.dev/dl/go1.27.1.windows-amd64.zip',
    'bytes' => 78931360,
    'sha256' => 'a3911b5e0e1b1053f25ed0675f4c1c6aad1e2bfcf253df2b9be4caabd2edd95d',
], 'Go fixture toolchain archive identity differs.');
fixtureEnsure($metadata['generators'][1]['toolchain'] === 'CMake 4.3.1-msvc1; MSVC 19.29.30159.0', 'C++ fixture toolchain identity differs.');

$corpusPath = $root . '/fixtures/' . $metadata['corpus']['path'];
fixtureEnsure(is_file($corpusPath), 'Fixture corpus is missing.');
fixtureEnsure(filesize($corpusPath) === $metadata['corpus']['bytes'], 'Fixture corpus byte count differs from metadata.');
fixtureEnsure(hash_file('sha256', $corpusPath) === $metadata['corpus']['sha256'], 'Fixture corpus digest differs from metadata.');
$records = LevelDbFixtureCorpus::read($corpusPath);
fixtureEnsure(count($records) === $metadata['corpus']['entries'], 'Fixture corpus entry count differs from metadata.');
fixtureEnsure($records[0] === ['key' => '00-empty-value', 'value' => ''], 'Fixture corpus lost its empty value.');
fixtureEnsure(str_contains($records[1]['key'], "\0") && str_contains($records[1]['value'], "\0"), 'Fixture corpus lost its NUL-bearing record.');

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-leveldb-fixtures-' . bin2hex(random_bytes(8));
fixtureEnsure(mkdir($temporaryRoot, 0700), 'Could not create fixture test directory.');
try {
    $rebuiltCorpus = $temporaryRoot . DIRECTORY_SEPARATOR . 'corpus.bin';
    [$status, $output] = fixtureProcess([PHP_BINARY, $root . '/fixtures/generators/build-corpus.php', $rebuiltCorpus]);
    fixtureEnsure($status === 0 && file_get_contents($rebuiltCorpus) === file_get_contents($corpusPath), 'Fixture corpus generator is not deterministic: ' . $output);

    $expectedTypes = ['none' => [0], 'raw-zlib' => [0, 4], 'zstd' => [0, 3]];
    $noneTable = null;
    foreach ($metadata['fixtures'] as $fixture) {
        fixtureEnsure(array_keys($fixture) === ['id', 'path', 'sha256', 'bytes', 'producer', 'compressionTypes', 'format'], 'Fixture entry has missing or unknown fields.');
        fixtureEnsure(isset($expectedTypes[$fixture['id']]) && $fixture['compressionTypes'] === $expectedTypes[$fixture['id']], 'Fixture compression declaration is invalid.');
        fixtureEnsure($fixture['format'] === 'ustar', 'Fixture archive format is not USTAR.');
        $archive = $root . '/fixtures/' . $fixture['path'];
        fixtureEnsure(is_file($archive) && filesize($archive) === $fixture['bytes'], "Fixture {$fixture['id']} has the wrong byte count.");
        fixtureEnsure(hash_file('sha256', $archive) === $fixture['sha256'], "Fixture {$fixture['id']} has the wrong digest.");
        $archiveEntries = UstarArchive::read($archive);
        foreach ($archiveEntries as $entry) {
            fixtureEnsure(!in_array(basename($entry['name']), ['LOCK', 'LOG', 'LOG.old'], true) && !str_ends_with($entry['name'], '.log'), 'Fixture archive contains mutable LevelDB process state.');
        }
        $destination = $temporaryRoot . DIRECTORY_SEPARATOR . $fixture['id'];
        UstarArchive::extract($archive, $destination);
        $rebuiltArchive = $temporaryRoot . DIRECTORY_SEPARATOR . $fixture['id'] . '-rebuilt.tar';
        DeterministicUstar::create($destination, $rebuiltArchive, ['LOCK', 'LOG', 'LOG.old']);
        fixtureEnsure(file_get_contents($rebuiltArchive) === file_get_contents($archive), "Fixture {$fixture['id']} archive assembly is not deterministic.");
        $inspection = LevelDbTableInspector::inspectDatabase($destination);
        fixtureEnsure($inspection['compressionTypes'] === $expectedTypes[$fixture['id']], "Fixture {$fixture['id']} physical compression tags differ.");
        if ($fixture['id'] === 'none') {
            $noneTable = $inspection['tables'][0];
        }
        if (extension_loaded('leveldb')) {
            [$probeStatus, $probeOutput] = fixtureProcess([
                PHP_BINARY,
                $root . '/tests/helpers/leveldb-fixture-probe.php',
                $destination,
                $corpusPath,
            ]);
            fixtureEnsure($probeStatus === 0, "Fixture {$fixture['id']} semantic probe failed: $probeOutput");
        }
    }

    $noneArchive = file_get_contents($root . '/fixtures/data/leveldb-none-v1.tar');
    fixtureEnsure(is_string($noneArchive), 'Could not read USTAR path-validation baseline.');
    $headerOffsets = fixtureUstarHeaderOffsets($noneArchive);
    fixtureEnsure(count($headerOffsets) >= 2, 'USTAR path-validation baseline has too few entries.');
    $unsafeNames = [
        'empty//component', 'dot/./component', 'parent/../escape', '/absolute',
        'windows\\separator', "control/\x01name", 'ads/name:stream',
        'trailing/name.', 'trailing/name ', 'trailing/name//', 'devices/CON',
        'devices/aux.txt', 'devices/PRN.bin', 'devices/NUL', 'devices/COM1.txt',
        'devices/lpt9', 'devices/CONIN$',
    ];
    foreach ($unsafeNames as $index => $unsafeName) {
        $unsafeArchive = fixtureRewriteUstarName($noneArchive, $headerOffsets[0], $unsafeName);
        $unsafePath = $temporaryRoot . DIRECTORY_SEPARATOR . "unsafe-path-$index.tar";
        fixtureEnsure(file_put_contents($unsafePath, $unsafeArchive) === strlen($unsafeArchive), 'Could not write unsafe USTAR test archive.');
        fixtureExpectUstarRejected($unsafePath, "USTAR reader accepted unsafe path case $index.");
    }
    $aliasArchive = fixtureRewriteUstarName($noneArchive, $headerOffsets[0], 'aliases/Entry');
    $aliasArchive = fixtureRewriteUstarName($aliasArchive, $headerOffsets[1], 'ALIASES/entry');
    $aliasPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'case-alias.tar';
    fixtureEnsure(file_put_contents($aliasPath, $aliasArchive) === strlen($aliasArchive), 'Could not write case-alias USTAR test archive.');
    fixtureExpectUstarRejected($aliasPath, 'USTAR reader accepted case-insensitive path aliases.');

    $oversizedPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'oversized.tar';
    $oversized = fopen($oversizedPath, 'x+b');
    fixtureEnsure(is_resource($oversized), 'Could not create oversized USTAR test archive.');
    fixtureEnsure(ftruncate($oversized, 67_109_376), 'Could not size oversized USTAR test archive.');
    fclose($oversized);
    fixtureExpectUstarRejected($oversizedPath, 'USTAR reader accepted an oversized archive.');

    fixtureEnsure(is_array($noneTable), 'Uncompressed fixture did not provide a mutation baseline.');
    $baseline = file_get_contents($noneTable['path']);
    fixtureEnsure(is_string($baseline), 'Could not read mutation baseline.');
    $mutations = [];
    $firstData = $noneTable['dataBlocks'][0];
    $mutations['payload'] = substr_replace($baseline, chr(ord($baseline[$firstData['offset']]) ^ 1), $firstData['offset'], 1);
    $mutations['truncated'] = substr($baseline, 0, -1);
    $mutations['crc'] = substr_replace($baseline, chr(ord($baseline[$firstData['trailerOffset'] + 1]) ^ 1), $firstData['trailerOffset'] + 1, 1);
    $unknown = substr_replace($baseline, "\xff", $firstData['trailerOffset'], 1);
    $payload = substr($unknown, $firstData['offset'], $firstData['size']);
    $unknown = substr_replace($unknown, pack('V', LevelDbTableInspector::maskedCrc32c($payload . "\xff")), $firstData['trailerOffset'] + 1, 4);
    $mutations['unknown-type'] = $unknown;
    $firstIndex = $noneTable['indexEntries'][0];
    fixtureEnsure($firstIndex['valueOffset'] >= 0, 'Mutation baseline index is not directly addressable.');
    $badHandle = substr_replace($baseline, str_repeat("\xff", $firstIndex['valueLength']), $firstIndex['valueOffset'], $firstIndex['valueLength']);
    $indexBlock = $noneTable['indexBlock'];
    $indexPayload = substr($badHandle, $indexBlock['offset'], $indexBlock['size']);
    $badHandle = substr_replace($badHandle, pack('V', LevelDbTableInspector::maskedCrc32c($indexPayload . chr($indexBlock['type']))), $indexBlock['trailerOffset'] + 1, 4);
    $mutations['bad-handle'] = $badHandle;

    $inspectionProbe = $root . '/tests/helpers/leveldb-sst-inspect-probe.php';
    foreach ($mutations as $name => $bytes) {
        $path = $temporaryRoot . DIRECTORY_SEPARATOR . "corrupt-$name.ldb";
        fixtureEnsure(file_put_contents($path, $bytes) === strlen($bytes), "Could not write $name mutation.");
        [$probeStatus] = fixtureProcess([PHP_BINARY, $inspectionProbe, $path]);
        fixtureEnsure($probeStatus !== 0, "SST inspector accepted the $name mutation.");
    }
} finally {
    fixtureRemoveTree($temporaryRoot);
}

echo "Independent LevelDB fixture tests passed.\n";
