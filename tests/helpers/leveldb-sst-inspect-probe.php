<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/LevelDbTableInspector.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: leveldb-sst-inspect-probe.php <table>\n");
    exit(2);
}

try {
    LevelDbTableInspector::inspectTable($argv[1]);
    echo "LevelDB table inspection passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
