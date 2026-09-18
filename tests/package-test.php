<?php

declare(strict_types=1);

require_once __DIR__ . '/../tools/PackageBuilder.php';

function packageTestEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
foreach (['windows-x86_64', 'windows-arm64'] as $target) {
    packageTestEnsure(isset(PackageBuilder::noticeFiles($target)['licenses/microsoft-vc143-runtime-NOTICE.txt']), 'Windows notice closure omits the Microsoft VC143 runtime notice.');
}
foreach (['linux-x86_64', 'linux-arm64', 'macos-x86_64', 'macos-arm64'] as $target) {
    $notices = PackageBuilder::noticeFiles($target);
    packageTestEnsure(array_keys($notices) === ['NOTICE', 'THIRD_PARTY_NOTICES.md'], 'Unix notice closure is not exact.');
}
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
    $safeLinkCreated = function_exists('symlink') && @symlink('php', $native . DIRECTORY_SEPARATOR . 'phar');

    $package = $outputParent . DIRECTORY_SEPARATOR . 'bin';
    PackageBuilder::assertNewDirectoryBelow($package, $outputParent, 'Test package');
    PackageBuilder::copyTree($native, $package);
    packageTestEnsure(!is_file($package . DIRECTORY_SEPARATOR . 'php.ini'), 'Ambient php.ini was copied.');
    if ($safeLinkCreated) {
        packageTestEnsure(!is_link($package . DIRECTORY_SEPARATOR . 'phar'), 'Safe runtime link was retained as a link.');
        packageTestEnsure(file_get_contents($package . DIRECTORY_SEPARATOR . 'phar') === 'binary', 'Safe runtime link was not materialized.');
    }

    if (function_exists('symlink')) {
        $escapingNative = $testParent . DIRECTORY_SEPARATOR . 'escaping-native';
        $escapingOutput = $outputParent . DIRECTORY_SEPARATOR . 'escaping-bin';
        mkdir($escapingNative, 0755, true);
        file_put_contents($testParent . DIRECTORY_SEPARATOR . 'outside', 'outside');
        if (@symlink('..' . DIRECTORY_SEPARATOR . 'outside', $escapingNative . DIRECTORY_SEPARATOR . 'escape')) {
            $caught = false;
            try {
                PackageBuilder::copyTree($escapingNative, $escapingOutput);
            } catch (RuntimeException) {
                $caught = true;
            }
            packageTestEnsure($caught, 'Escaping native runtime link was accepted.');
        }
    }

    $template = "extension_dir = \"\${BEDRIOX_RUNTIME_ROOT}/ext\"\n@BEDRIOX_DYNAMIC_EXTENSIONS@\n";
    $ini = PackageBuilder::renderIni($template, $package);
    packageTestEnsure(str_contains($ini, 'extension = "${BEDRIOX_RUNTIME_ROOT}/ext/leveldb.so"'), 'Shared LevelDB was not enabled.');
    packageTestEnsure(str_contains($ini, 'zend_extension = "${BEDRIOX_RUNTIME_ROOT}/ext/opcache.so"'), 'Shared OPcache was not enabled as a Zend extension.');
    packageTestEnsure(!str_contains($ini, '@BEDRIOX_DYNAMIC_EXTENSIONS@'), 'Dynamic extension marker survived rendering.');
    file_put_contents($package . DIRECTORY_SEPARATOR . 'php.ini', $ini);
    file_put_contents($package . DIRECTORY_SEPARATOR . 'runtime-manifest.json', '{}');
    file_put_contents($package . DIRECTORY_SEPARATOR . 'artifact.sha256', 'excluded');

    $caught = false;
    try {
        PackageBuilder::inventory($package);
    } catch (RuntimeException) {
        $caught = true;
    }
    packageTestEnsure($caught, 'An internal checksum sidecar was accepted.');
    unlink($package . DIRECTORY_SEPARATOR . 'artifact.sha256');

    $inventory = PackageBuilder::inventory($package);
    $keys = array_keys($inventory);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    packageTestEnsure($keys === $sorted, 'Inventory keys are not sorted.');
    packageTestEnsure(!isset($inventory['runtime-manifest.json']), 'Manifest inventoried itself.');
    packageTestEnsure(array_keys($inventory['php']) === ['bytes', 'sha256'], 'Inventory metadata shape is incorrect.');

    $windowsRuntime = $testParent . DIRECTORY_SEPARATOR . 'windows-runtime';
    mkdir($windowsRuntime . DIRECTORY_SEPARATOR . 'ext', 0755, true);
    file_put_contents($windowsRuntime . DIRECTORY_SEPARATOR . 'php.exe', 'pe');
    file_put_contents($windowsRuntime . DIRECTORY_SEPARATOR . 'php84.dll', 'engine');
    file_put_contents($windowsRuntime . DIRECTORY_SEPARATOR . 'leveldb.dll', 'leveldb');
    file_put_contents($windowsRuntime . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'php_leveldb.dll', 'extension');
    PackageBuilder::assertNativeRuntimeLayout($windowsRuntime, 'windows-x86_64');

    file_put_contents($windowsRuntime . DIRECTORY_SEPARATOR . 'php.lib', 'development');
    $caught = false;
    try {
        PackageBuilder::assertNativeRuntimeLayout($windowsRuntime, 'windows-x86_64');
    } catch (RuntimeException) {
        $caught = true;
    }
    packageTestEnsure($caught, 'Windows development import library was accepted into the runtime stage.');
    unlink($windowsRuntime . DIRECTORY_SEPARATOR . 'php.lib');

    mkdir($windowsRuntime . DIRECTORY_SEPARATOR . 'include');
    $caught = false;
    try {
        PackageBuilder::assertNativeRuntimeLayout($windowsRuntime, 'windows-x86_64');
    } catch (RuntimeException) {
        $caught = true;
    }
    packageTestEnsure($caught, 'Windows SDK directory was accepted into the runtime stage.');
    rmdir($windowsRuntime . DIRECTORY_SEPARATOR . 'include');

    unlink($windowsRuntime . DIRECTORY_SEPARATOR . 'leveldb.dll');
    $caught = false;
    try {
        PackageBuilder::assertNativeRuntimeLayout($windowsRuntime, 'windows-x86_64');
    } catch (RuntimeException) {
        $caught = true;
    }
    packageTestEnsure($caught, 'Windows runtime stage without leveldb.dll was accepted.');

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
