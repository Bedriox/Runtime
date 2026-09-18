<?php

declare(strict_types=1);

require_once __DIR__ . '/DeterministicUstar.php';
require_once dirname(__DIR__, 2) . '/tools/LevelDbFixtureCorpus.php';
require_once dirname(__DIR__, 2) . '/tools/LevelDbTableInspector.php';

$options = getopt('', ['none-db:', 'raw-db:', 'zstd-db:', 'output:']);
$optionNames = is_array($options) ? array_keys($options) : [];
sort($optionNames, SORT_STRING);
if ($optionNames !== ['none-db', 'output', 'raw-db', 'zstd-db']) {
    fwrite(STDERR, "Usage: rebuild.php --none-db=<dir> --raw-db=<dir> --zstd-db=<dir> --output=<new-dir>\n");
    exit(2);
}

$output = $options['output'];
if (!is_string($output) || file_exists($output) || !mkdir($output, 0777, true)) {
    throw new RuntimeException('Fixture rebuild output must be a new directory.');
}
$corpusDirectory = $output . DIRECTORY_SEPARATOR . 'corpus';
$dataDirectory = $output . DIRECTORY_SEPARATOR . 'data';
if (!mkdir($corpusDirectory) || !mkdir($dataDirectory)) {
    throw new RuntimeException('Could not create fixture rebuild directories.');
}

$corpus = $corpusDirectory . DIRECTORY_SEPARATOR . 'leveldb-corpus-v1.bin';
$command = [PHP_BINARY, __DIR__ . '/build-corpus.php', $corpus];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the corpus generator.');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('Corpus generation failed: ' . trim((string) $stdout . ' ' . (string) $stderr));
}

$fixtures = [
    'none' => ['directory' => $options['none-db'], 'types' => [0]],
    'raw-zlib' => ['directory' => $options['raw-db'], 'types' => [0, 4]],
    'zstd' => ['directory' => $options['zstd-db'], 'types' => [0, 3]],
];
foreach ($fixtures as $name => $fixture) {
    if (!is_string($fixture['directory'])) {
        throw new RuntimeException("Fixture source directory is invalid: $name");
    }
    $inspection = LevelDbTableInspector::inspectDatabase($fixture['directory']);
    if ($inspection['compressionTypes'] !== $fixture['types']) {
        throw new RuntimeException("Fixture source has the wrong physical compression types: $name");
    }
    $archive = $dataDirectory . DIRECTORY_SEPARATOR . "leveldb-$name-v1.tar";
    DeterministicUstar::create($fixture['directory'], $archive, ['LOCK', 'LOG', 'LOG.old']);
    echo sprintf(
        "%s %d %s\n",
        str_replace('\\', '/', substr($archive, strlen($output) + 1)),
        filesize($archive),
        hash_file('sha256', $archive),
    );
}
echo sprintf("corpus/leveldb-corpus-v1.bin %d %s\n", filesize($corpus), hash_file('sha256', $corpus));
