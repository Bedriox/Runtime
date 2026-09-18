<?php

declare(strict_types=1);

require_once __DIR__ . '/PackageArchive.php';

if ($argc !== 6 || $argv[1] !== '--extract-to') {
    fwrite(STDERR, "Usage: package-verify.php --extract-to <directory> <zip|tar.gz> <archive> <package-root>\n");
    exit(2);
}

try {
    echo PackageArchive::verifyAndExtract($argv[3], $argv[4], $argv[5], $argv[2]) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
