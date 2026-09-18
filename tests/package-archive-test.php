<?php

declare(strict_types=1);

require_once __DIR__ . '/../tools/PackageArchive.php';

function archiveTestEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function archiveTestRemove(string $root): void
{
    for ($attempt = 0; $attempt < 10; ++$attempt) {
        clearstatcache(true, $root);
        if (!file_exists($root) && !is_link($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() || $entry->isLink()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($root);
        if ($attempt < 9) {
            usleep(50_000);
        }
    }
    throw new RuntimeException('Could not clean the package archive test root: ' . $root);
}

function archiveTestPackage(string $root, string $executable): void
{
    mkdir($root . DIRECTORY_SEPARATOR . 'ext', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . $executable, "executable\n");
    chmod($root . DIRECTORY_SEPARATOR . $executable, 0755);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'php.ini', "setting=value\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'module.bin', "module\0bytes");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'runtime-manifest.json', "{}\n");
}

/** @param list<string> $command @param array<string, string> $environment @return array{int, string, string} */
function archiveTestRun(array $command, array $environment): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start relocation probe test.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        proc_close($process),
        $stdout === false ? '' : $stdout,
        $stderr === false ? '' : $stderr,
    ];
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'archive-test-'
    . getmypid() . '-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0755, true)) {
    throw new RuntimeException('Could not create archive test root.');
}

try {
    $windowsPackage = $root . DIRECTORY_SEPARATOR . 'windows-package';
    archiveTestPackage($windowsPackage, 'php.exe');
    $zipPath = $root . DIRECTORY_SEPARATOR . 'runtime.zip';
    PackageArchive::create('zip', $windowsPackage, $zipPath);
    file_put_contents($zipPath . '.sha256', hash_file('sha256', $zipPath) . "  runtime.zip\n");
    $zipDestination = $root . DIRECTORY_SEPARATOR . 'zip extraction with spaces';
    $zipBin = PackageArchive::verifyAndExtract('zip', $zipPath, $windowsPackage, $zipDestination);
    archiveTestEnsure(is_file($zipBin . DIRECTORY_SEPARATOR . 'php.exe'), 'ZIP executable was not extracted.');

    $existingArchive = $root . DIRECTORY_SEPARATOR . 'existing.zip';
    $existingChecksum = $existingArchive . '.sha256';
    file_put_contents($existingArchive, 'existing archive bytes');
    file_put_contents($existingChecksum, 'existing checksum bytes');
    $caught = false;
    try {
        PackageArchive::create('zip', $windowsPackage, $existingArchive);
    } catch (RuntimeException) {
        $caught = true;
    }
    archiveTestEnsure($caught, 'Pre-existing archive output was accepted.');
    archiveTestEnsure(file_get_contents($existingArchive) === 'existing archive bytes', 'Pre-existing archive output was changed.');
    archiveTestEnsure(file_get_contents($existingChecksum) === 'existing checksum bytes', 'Adjacent pre-existing checksum was changed.');

    $publishCandidate = $root . DIRECTORY_SEPARATOR . 'publish-candidate.zip';
    file_put_contents($publishCandidate, 'candidate bytes');
    $caught = false;
    try {
        PackageArchive::publish($publishCandidate, $existingArchive);
    } catch (RuntimeException) {
        $caught = true;
    }
    archiveTestEnsure($caught, 'Publication replaced an existing archive.');
    archiveTestEnsure(file_get_contents($existingArchive) === 'existing archive bytes', 'Concurrent publication changed an existing archive.');
    archiveTestEnsure(file_get_contents($publishCandidate) === 'candidate bytes', 'Rejected publication removed its candidate.');

    $successfulCandidate = $root . DIRECTORY_SEPARATOR . 'successful-candidate.zip';
    $successfulArchive = $root . DIRECTORY_SEPARATOR . 'successful-publication.zip';
    file_put_contents($successfulCandidate, 'successful publication bytes');
    PackageArchive::publish($successfulCandidate, $successfulArchive);
    archiveTestEnsure(!file_exists($successfulCandidate), 'Successful publication retained its candidate path.');
    archiveTestEnsure(file_get_contents($successfulArchive) === 'successful publication bytes', 'Successful publication changed archive bytes.');
    $successfulChecksum = $successfulArchive . '.sha256';
    $successfulHash = PackageArchive::writeChecksum($successfulArchive, $successfulChecksum, 'successful-publication.zip');
    archiveTestEnsure(
        file_get_contents($successfulChecksum) === $successfulHash . '  successful-publication.zip' . PHP_EOL,
        'Successful checksum publication wrote unexpected contents.',
    );

    $checksumCollisionArchive = $root . DIRECTORY_SEPARATOR . 'checksum-collision.zip';
    $checksumCollisionSidecar = $checksumCollisionArchive . '.sha256';
    file_put_contents($checksumCollisionArchive, 'published bytes');
    file_put_contents($checksumCollisionSidecar, 'independent checksum bytes');
    $caught = false;
    try {
        PackageArchive::writeChecksum($checksumCollisionArchive, $checksumCollisionSidecar, 'checksum-collision.zip');
    } catch (RuntimeException) {
        $caught = true;
    }
    archiveTestEnsure($caught, 'Checksum publication replaced an existing sidecar.');
    archiveTestEnsure(file_get_contents($checksumCollisionSidecar) === 'independent checksum bytes', 'Existing checksum bytes were changed.');

    $zip = new ZipArchive();
    archiveTestEnsure($zip->open($zipPath) === true, 'Could not reopen ZIP fixture.');
    archiveTestEnsure($zip->locateName('bin/runtime.zip.sha256') === false, 'Checksum sidecar was placed in ZIP.');
    $zip->addFromString('bin/unexpected.txt', 'unexpected');
    $zip->close();
    $caught = false;
    try {
        PackageArchive::verifyAndExtract('zip', $zipPath, $windowsPackage, $root . DIRECTORY_SEPARATOR . 'tampered extraction');
    } catch (RuntimeException) {
        $caught = true;
    }
    archiveTestEnsure($caught, 'ZIP with an undeclared entry was accepted.');

    $unixPackage = $root . DIRECTORY_SEPARATOR . 'unix-package';
    archiveTestPackage($unixPackage, 'php');
    $tarPath = $root . DIRECTORY_SEPARATOR . 'runtime.tar.gz';
    PackageArchive::create('tar.gz', $unixPackage, $tarPath);
    $tarDestination = $root . DIRECTORY_SEPARATOR . 'tar extraction with spaces';
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    [$tarStatus, $tarOutput, $tarError] = archiveTestRun([
        PHP_BINARY,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'package-verify.php',
        '--extract-to', $tarDestination, 'tar.gz', $tarPath, $unixPackage,
    ], $environment);
    archiveTestEnsure($tarStatus === 0, 'Tar verification subprocess failed: ' . trim($tarError));
    $tarBin = trim($tarOutput);
    archiveTestEnsure($tarBin === $tarDestination . DIRECTORY_SEPARATOR . 'bin', 'Tar verifier returned the wrong extraction root.');
    if (PHP_OS_FAMILY !== 'Windows') {
        archiveTestEnsure((fileperms($tarBin . DIRECTORY_SEPARATOR . 'php') & 0111) !== 0, 'Tar executable mode was not retained.');
    }

    $tamperedTarPackage = $root . DIRECTORY_SEPARATOR . 'tampered-tar-package';
    archiveTestPackage($tamperedTarPackage, 'php');
    file_put_contents($tamperedTarPackage . DIRECTORY_SEPARATOR . 'unexpected.bin', str_repeat('x', 1024 * 1024));
    $tamperedTarPath = $root . DIRECTORY_SEPARATOR . 'tampered-runtime.tar.gz';
    PackageArchive::create('tar.gz', $tamperedTarPackage, $tamperedTarPath);
    $caught = false;
    try {
        PackageArchive::verifyAndExtract(
            'tar.gz',
            $tamperedTarPath,
            $unixPackage,
            $root . DIRECTORY_SEPARATOR . 'tampered tar extraction',
        );
    } catch (RuntimeException) {
        $caught = true;
    }
    archiveTestEnsure($caught, 'Tar archive with an unexpected oversized entry was accepted.');

    file_put_contents($unixPackage . DIRECTORY_SEPARATOR . 'forbidden.sha256', 'forbidden');
    $caught = false;
    try {
        PackageArchive::create('tar.gz', $unixPackage, $root . DIRECTORY_SEPARATOR . 'forbidden.tar.gz');
    } catch (RuntimeException) {
        $caught = true;
    }
    archiveTestEnsure($caught, 'Package with an internal checksum sidecar was accepted.');

    unset($environment['BEDRIOX_RUNTIME_ROOT']);
    [$unsetStatus, , $unsetError] = archiveTestRun(
        [PHP_BINARY, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'relocation-probe.php', dirname(PHP_BINARY)],
        $environment,
    );
    archiveTestEnsure($unsetStatus !== 0 && str_contains($unsetError, 'BEDRIOX_RUNTIME_ROOT'), 'Unset runtime root did not fail closed.');
    $environment['BEDRIOX_RUNTIME_ROOT'] = $root;
    [$wrongStatus, , $wrongError] = archiveTestRun(
        [PHP_BINARY, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'relocation-probe.php', dirname(PHP_BINARY)],
        $environment,
    );
    archiveTestEnsure($wrongStatus !== 0 && str_contains($wrongError, 'BEDRIOX_RUNTIME_ROOT'), 'Wrong runtime root did not fail closed.');

    echo "Package archive tests passed.\n";
} finally {
    archiveTestRemove($root);
}
