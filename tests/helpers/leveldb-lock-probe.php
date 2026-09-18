<?php

declare(strict_types=1);

if ($argc !== 2 || !is_string($argv[1]) || $argv[1] === '') {
    fwrite(STDERR, "Usage: leveldb-lock-probe.php <database>\n");
    exit(64);
}
try {
    $database = new LevelDB($argv[1], ['create_if_missing' => false]);
    $database->close();
    fwrite(STDERR, "A second process opened a locked LevelDB database.\n");
    exit(1);
} catch (Throwable) {
    echo "LevelDB lock was enforced.\n";
}
