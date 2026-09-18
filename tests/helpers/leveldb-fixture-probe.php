<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/LevelDbFixtureCorpus.php';

if ($argc !== 3 || !extension_loaded('leveldb')) {
    fwrite(STDERR, "Usage: leveldb-fixture-probe.php <database> <corpus>\n");
    exit(2);
}

try {
    $records = LevelDbFixtureCorpus::read($argv[2]);
    $database = new LevelDB(
        $argv[1],
        ['create_if_missing' => false],
        ['verify_check_sum' => true, 'fill_cache' => false],
    );
    foreach ($records as $record) {
        if ($database->get($record['key'], ['verify_check_sum' => true, 'fill_cache' => false]) !== $record['value']) {
            throw new RuntimeException('Fixture lookup returned the wrong value.');
        }
    }
    if ($database->get('missing-fixture-key', ['verify_check_sum' => true]) !== false) {
        throw new RuntimeException('Fixture missing-key lookup did not return false.');
    }
    $index = 0;
    foreach ($database->getIterator(['verify_check_sum' => true, 'fill_cache' => false]) as $key => $value) {
        if (!isset($records[$index]) || $key !== $records[$index]['key'] || $value !== $records[$index]['value']) {
            throw new RuntimeException("Fixture iteration differs at entry $index.");
        }
        $index++;
    }
    if ($index !== count($records)) {
        throw new RuntimeException('Fixture iteration returned the wrong entry count.');
    }
    unset($database);
    gc_collect_cycles();
    echo "LevelDB fixture semantics passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
