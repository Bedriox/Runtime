<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: relocation-probe.php <expected-runtime-root>\n");
    exit(2);
}

try {
    $expected = realpath($argv[1]);
    $configured = getenv('BEDRIOX_RUNTIME_ROOT');
    $configuredReal = is_string($configured) && $configured !== '' ? realpath($configured) : false;
    if ($expected === false || $configuredReal === false || $configuredReal !== $expected) {
        throw new RuntimeException('BEDRIOX_RUNTIME_ROOT must resolve to the relocated package root.');
    }

    $binary = realpath(PHP_BINARY);
    $expectedBinary = realpath($expected . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'));
    if ($binary === false || $expectedBinary === false || $binary !== $expectedBinary) {
        throw new RuntimeException('The relocation probe is not running with the relocated PHP executable.');
    }

    $loadedIni = php_ini_loaded_file();
    $expectedIni = realpath($expected . DIRECTORY_SEPARATOR . 'php.ini');
    if (!is_string($loadedIni) || realpath($loadedIni) !== $expectedIni) {
        throw new RuntimeException('The relocated php.ini is not loaded.');
    }

    $opensslConfiguration = getenv('OPENSSL_CONF');
    $expectedOpenSslConfiguration = realpath($expected . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf');
    if (
        !is_string($opensslConfiguration)
        || $opensslConfiguration === ''
        || $expectedOpenSslConfiguration === false
        || realpath($opensslConfiguration) !== $expectedOpenSslConfiguration
    ) {
        throw new RuntimeException('OPENSSL_CONF must resolve inside the relocated package.');
    }

    $cacheRoot = getenv('BEDRIOX_RUNTIME_CACHE');
    $opcodeCache = is_string($cacheRoot) && $cacheRoot !== ''
        ? realpath($cacheRoot . DIRECTORY_SEPARATOR . 'opcache')
        : false;
    $configuredOpcodeCache = ini_get('opcache.file_cache');
    if (
        $opcodeCache === false
        || !is_string($configuredOpcodeCache)
        || realpath($configuredOpcodeCache) !== $opcodeCache
    ) {
        throw new RuntimeException('BEDRIOX_RUNTIME_CACHE must provide the configured external opcode cache.');
    }

    $normalizePath = static function (string $path): string {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    };
    foreach (['extension_dir' => 'ext', 'curl.cainfo' => 'certs/cacert.pem', 'openssl.cafile' => 'certs/cacert.pem'] as $setting => $relative) {
        $value = ini_get($setting);
        $wanted = $expected . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_string($value) || $normalizePath($value) !== $normalizePath($wanted)) {
            throw new RuntimeException($setting . ' does not resolve inside the relocated package.');
        }
    }
    foreach (['leveldb', 'Zend OPcache'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Relocated runtime did not load required extension: ' . $extension);
        }
    }
    echo "Relocation root and configuration are valid.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
