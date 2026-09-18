<?php

declare(strict_types=1);

require_once __DIR__ . '/../tools/PackageBuilder.php';

function packageTestEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function packageTestProcess(array $command): int
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start archive test process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Archive helper failed: ' . trim(($stdout ?: '') . ' ' . ($stderr ?: '')));
    }
    return $status;
}

$root = dirname(__DIR__);
$testParent = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'package-test-' . getmypid();
if (!mkdir($testParent, 0755, true)) {
    throw new RuntimeException('Could not create package test root.');
}

try {
    $native = $testParent . DIRECTORY_SEPARATOR . 'native';
    $outputParent = $testParent . DIRECTORY_SEPARATOR . 'output';
    mkdir($native . DIRECTORY_SEPARATOR . 'ext', 0755, true);
    mkdir($outputParent, 0755, true);
    file_put_contents($native . DIRECTORY_SEPARATOR . 'php', 'binary');
    file_put_contents($native . DIRECTORY_SEPARATOR . 'php.ini', 'ambient path must not survive');
    file_put_contents($native . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'leveldb.so', 'leveldb');
    file_put_contents($native . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'opcache.so', 'opcache');

    $package = $outputParent . DIRECTORY_SEPARATOR . 'bin';
    PackageBuilder::assertNewDirectoryBelow($package, $outputParent, 'Test package');
    PackageBuilder::copyTree($native, $package);
    packageTestEnsure(!is_file($package . DIRECTORY_SEPARATOR . 'php.ini'), 'Ambient php.ini was copied.');

    $template = "extension_dir = \"\${BEDRIOX_RUNTIME_ROOT}/ext\"\n@BEDRIOX_DYNAMIC_EXTENSIONS@\n";
    $ini = PackageBuilder::renderIni($template, $package);
    packageTestEnsure(str_contains($ini, 'extension = "${BEDRIOX_RUNTIME_ROOT}/ext/leveldb.so"'), 'Shared LevelDB was not enabled.');
    packageTestEnsure(str_contains($ini, 'zend_extension = "${BEDRIOX_RUNTIME_ROOT}/ext/opcache.so"'), 'Shared OPcache was not enabled as a Zend extension.');
    packageTestEnsure(!str_contains($ini, '@BEDRIOX_DYNAMIC_EXTENSIONS@'), 'Dynamic extension marker survived rendering.');
    file_put_contents($package . DIRECTORY_SEPARATOR . 'php.ini', $ini);
    file_put_contents($package . DIRECTORY_SEPARATOR . 'runtime-manifest.json', '{}');
    file_put_contents($package . DIRECTORY_SEPARATOR . 'artifact.sha256', 'excluded');

    $inventory = PackageBuilder::inventory($package);
    $keys = array_keys($inventory);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    packageTestEnsure($keys === $sorted, 'Inventory keys are not sorted.');
    packageTestEnsure(!isset($inventory['runtime-manifest.json']), 'Manifest inventoried itself.');
    packageTestEnsure(!isset($inventory['artifact.sha256']), 'Checksum sidecar was inventoried.');
    packageTestEnsure(array_keys($inventory['php']) === ['bytes', 'sha256'], 'Inventory metadata shape is incorrect.');

    $archiveTool = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'package-archive.php';
    $zipPath = $testParent . DIRECTORY_SEPARATOR . 'runtime.zip';
    packageTestProcess([PHP_BINARY, $archiveTool, 'zip', $package, $zipPath]);
    $zip = new ZipArchive();
    packageTestEnsure($zip->open($zipPath) === true, 'Could not open package ZIP.');
    packageTestEnsure($zip->locateName('bin/php') !== false, 'ZIP does not contain the bin layout.');
    $zip->close();
    $tarGzPath = $testParent . DIRECTORY_SEPARATOR . 'runtime.tar.gz';
    packageTestProcess([PHP_BINARY, $archiveTool, 'tar.gz', $package, $tarGzPath]);
    packageTestEnsure(is_file($tarGzPath) && filesize($tarGzPath) > 0, 'Tar package was not created.');

    $caught = false;
    try {
        PackageBuilder::assertNewDirectoryBelow($testParent . DIRECTORY_SEPARATOR . 'escape', $outputParent, 'Escaping package');
    } catch (RuntimeException) {
        $caught = true;
    }
    packageTestEnsure($caught, 'Package path escaping its parent was accepted.');
    echo "Package builder tests passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testParent, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile() || $entry->isLink()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($testParent);
}
