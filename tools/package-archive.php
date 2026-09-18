<?php

declare(strict_types=1);

require_once __DIR__ . '/PackageArchive.php';

if ($argc !== 4 || !in_array($argv[1], ['zip', 'tar.gz'], true)) {
    fwrite(STDERR, "Usage: package-archive.php <zip|tar.gz> <package-root> <archive>\n");
    exit(2);
}

try {
    PackageArchive::create($argv[1], $argv[2], $argv[3]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
