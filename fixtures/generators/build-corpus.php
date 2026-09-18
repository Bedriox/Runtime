<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/LevelDbFixtureCorpus.php';

$output = $argv[1] ?? dirname(__DIR__) . '/corpus/leveldb-corpus-v1.bin';
$records = [
    ['key' => '00-empty-value', 'value' => ''],
    ['key' => "01-nul\0key", 'value' => "nul\0value"],
    ['key' => "02-binary-\xff", 'value' => implode('', array_map(chr(...), range(0, 255)))],
];
for ($index = 0; $index < 48; $index++) {
    $key = '';
    $keySeed = "bedriox-leveldb-key-$index";
    while (strlen($key) < 192) {
        $keySeed = hash('sha256', $keySeed, true);
        $key .= $keySeed;
    }
    $value = '';
    if (($index % 2) === 0) {
        $value = str_repeat(chr(65 + ($index % 26)), 2048);
    } else {
        $valueSeed = "bedriox-leveldb-value-$index";
        while (strlen($value) < 2048) {
            $valueSeed = hash('sha256', $valueSeed, true);
            $value .= $valueSeed;
        }
        $value = substr($value, 0, 2048);
    }
    $records[] = [
        'key' => "\x40" . substr($key, 0, 192) . pack('n', $index),
        'value' => $value,
    ];
}
$records[] = [
    'key' => "\x7f-long-key-" . str_repeat('k', 256),
    'value' => str_repeat("tail\0", 512),
];
usort($records, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));

$encoded = LevelDbFixtureCorpus::encode($records);
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException("Could not create corpus directory: $directory");
}
if (file_put_contents($output, $encoded) !== strlen($encoded)) {
    throw new RuntimeException("Could not write fixture corpus: $output");
}

echo sprintf("Wrote %d records to %s.\n", count($records), $output);
