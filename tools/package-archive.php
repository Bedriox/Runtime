<?php

declare(strict_types=1);

if ($argc !== 4 || !in_array($argv[1], ['zip', 'tar.gz'], true)) {
    fwrite(STDERR, "Usage: package-archive.php <zip|tar.gz> <package-root> <archive>\n");
    exit(2);
}

$format = $argv[1];
$root = realpath($argv[2]);
$archive = $argv[3];
$archiveNormalized = str_replace('\\', '/', $archive);
$archiveParent = realpath(dirname($archive));
if (
    $root === false
    || !is_dir($root)
    || preg_match('#^(?:[A-Za-z]:/|/)#D', $archiveNormalized) !== 1
    || preg_match('#(?:^|/)\.\.?(/|$)#', $archiveNormalized) === 1
    || $archiveParent === false
    || !is_dir($archiveParent)
    || file_exists($archive)
    || is_link($archive)
) {
    fwrite(STDERR, "Unsafe or unavailable archive input/output.\n");
    exit(2);
}
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if ($entry->isLink() || !$entry->isFile()) {
        fwrite(STDERR, "Package contains an unsupported filesystem entry.\n");
        exit(2);
    }
    $relative = 'bin/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    $files[$relative] = $entry->getPathname();
}
ksort($files, SORT_STRING);

try {
    if ($format === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create ZIP archive.');
        }
        try {
            foreach ($files as $relative => $path) {
                if (!$zip->addFile($path, $relative)) {
                    throw new RuntimeException('Could not add file to ZIP: ' . $relative);
                }
            }
        } finally {
            $zip->close();
        }
    } else {
        if (!str_ends_with($archive, '.tar.gz')) {
            throw new RuntimeException('Tar archive name must end in .tar.gz.');
        }
        $tar = substr($archive, 0, -3);
        if (file_exists($tar)) {
            throw new RuntimeException('Intermediate tar already exists.');
        }
        $phar = new PharData($tar);
        foreach ($files as $relative => $path) {
            $phar->addFile($path, $relative);
        }
        $phar->compress(Phar::GZ);
        unset($phar);
        if (!is_file($archive) || !unlink($tar)) {
            throw new RuntimeException('Could not finalize tar.gz archive.');
        }
    }
} catch (Throwable $exception) {
    @unlink($archive);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
