<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/LevelDbFixtureCorpus.php';
require_once dirname(__DIR__) . '/tools/LevelDbTableInspector.php';
require_once dirname(__DIR__) . '/tools/UstarArchive.php';

const REQUIRED_EXTENSIONS = [
    'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gmp', 'hash', 'iconv', 'json', 'libxml',
    'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'phar', 'session', 'simplexml',
    'sockets', 'sodium', 'sqlite3', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter',
    'zip', 'zlib',
];
const EXPECTED_PHP_VERSION = '8.4.25';
const CURL_PEER_VERIFICATION_ERROR = 60;

final class Qualification
{
    /** @var list<string> */
    private array $failures = [];
    private int $passed = 0;
    private int $skipped = 0;

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            ++$this->passed;
            echo '[PASS] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $this->failures[] = $name . ': ' . $exception->getMessage();
            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        }
    }

    public function skip(string $name, string $reason): void
    {
        ++$this->skipped;
        echo '[SKIP] ' . $name . ': ' . $reason . PHP_EOL;
    }

    public function finish(): never
    {
        printf(
            "Qualification summary: %d passed, %d skipped, %d failed.\n",
            $this->passed,
            $this->skipped,
            count($this->failures),
        );
        exit($this->failures === [] ? 0 : 1);
    }
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readFileStrict(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
}

/** @return array{int, string, string} */
function runProcess(array $command, ?string $workingDirectory = null): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
}

function phpCommand(array $arguments): array
{
    $command = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if (is_string($ini) && $ini !== '') {
        $command[] = '-c';
        $command[] = $ini;
    } else {
        $command[] = '-n';
    }
    return [...$command, ...$arguments];
}

function makeTemporaryDirectory(): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        $path = $base . DIRECTORY_SEPARATOR . 'bedriox-runtime-' . bin2hex(random_bytes(8));
        if (@mkdir($path, 0700)) {
            return $path;
        }
    }
    throw new RuntimeException('Could not create a private temporary directory.');
}

function removeTemporaryTree(string $path, string $expectedParent): void
{
    $resolvedParent = realpath($expectedParent);
    $resolvedPath = realpath($path);
    if ($resolvedParent === false || $resolvedPath === false) {
        return;
    }
    $prefix = rtrim($resolvedParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    ensure(str_starts_with($resolvedPath . DIRECTORY_SEPARATOR, $prefix), 'Temporary cleanup escaped its parent.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            @unlink($entry->getPathname());
        } else {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($resolvedPath);
}

/** @return array<string, mixed> */
function readJsonObject(string $path): array
{
    $decoded = json_decode(readFileStrict($path), true, 64, JSON_THROW_ON_ERROR);
    ensure(is_array($decoded) && !array_is_list($decoded), $path . ' must contain a JSON object.');
    return $decoded;
}

function packageExecutable(string $root): string
{
    return $root . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
}

function validatePackage(string $root): void
{
    $executable = packageExecutable($root);
    ensure(is_file($executable), 'Packaged PHP executable is missing.');
    ensure(realpath($executable) === realpath(PHP_BINARY), 'Qualification is not running with the packaged PHP executable.');
    foreach ([
        'php.ini',
        'certs/cacert.pem',
        'config/openssl.cnf',
        'runtime-manifest.json',
        'NOTICE',
        'THIRD_PARTY_NOTICES.md',
    ] as $relative) {
        ensure(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), $relative . ' is missing.');
    }
    $licenseRoot = $root . DIRECTORY_SEPARATOR . 'licenses';
    ensure(is_dir($licenseRoot), 'licenses directory is missing.');
    $licenses = glob($licenseRoot . DIRECTORY_SEPARATOR . '*.txt');
    ensure(is_array($licenses) && count($licenses) >= 5, 'Packaged dependency licenses are incomplete.');

    $manifest = readJsonObject($root . DIRECTORY_SEPARATOR . 'runtime-manifest.json');
    foreach (['schemaVersion', 'target', 'architecture', 'phpVersion', 'threadSafe', 'compiler', 'linker', 'buildPolicy', 'sources', 'files'] as $field) {
        ensure(array_key_exists($field, $manifest), 'Runtime manifest is missing ' . $field . '.');
    }
    ensure($manifest['schemaVersion'] === 2, 'Runtime manifest schema is unsupported.');
    ensure(is_string($manifest['target']) && in_array($manifest['target'], [
        'windows-x86_64', 'linux-x86_64', 'linux-arm64', 'macos-x86_64', 'macos-arm64', 'windows-arm64',
    ], true), 'Runtime manifest target is invalid.');
    ensure($manifest['phpVersion'] === PHP_VERSION, 'Runtime manifest PHP version does not match the executable.');
    ensure(is_bool($manifest['threadSafe']) && $manifest['threadSafe'] === (bool) PHP_ZTS, 'Runtime manifest thread-safety choice does not match the executable.');
    $target = $manifest['target'];
    $microsoftNotice = $root . DIRECTORY_SEPARATOR . 'licenses' . DIRECTORY_SEPARATOR . 'microsoft-vc143-runtime-NOTICE.txt';
    if (str_starts_with($target, 'windows-')) {
        ensure(is_file($microsoftNotice), 'The Microsoft VC143 runtime notice is missing from a Windows package.');
    } else {
        ensure(!file_exists($microsoftNotice), 'A non-Windows package contains the Microsoft VC143 runtime notice.');
    }
    $expectedFamily = str_starts_with($target, 'windows-') ? 'Windows' : (str_starts_with($target, 'macos-') ? 'Darwin' : 'Linux');
    ensure(PHP_OS_FAMILY === $expectedFamily, 'Runtime manifest target operating system does not match the executable.');
    ensure($manifest['threadSafe'] === str_starts_with($target, 'windows-'), 'Runtime manifest thread-safety choice does not match the target contract.');
    $machine = strtolower(php_uname('m'));
    if (str_ends_with($target, '-x86_64')) {
        ensure(in_array($machine, ['amd64', 'x86_64'], true), 'Runtime manifest x86-64 target does not match architecture ' . $machine . '.');
    } else {
        ensure(in_array($machine, ['aarch64', 'arm64'], true), 'Runtime manifest ARM64 target does not match architecture ' . $machine . '.');
    }
    ensure(is_array($manifest['sources']) && $manifest['sources'] !== [], 'Runtime manifest has no source inventory.');
    foreach (['php', 'leveldb', 'php-leveldb', 'ca-bundle'] as $source) {
        ensure(isset($manifest['sources'][$source]) && is_array($manifest['sources'][$source]), 'Runtime manifest is missing source ' . $source . '.');
        ensure(preg_match('/^[0-9a-f]{64}$/D', (string) ($manifest['sources'][$source]['sha256'] ?? '')) === 1, $source . ' has an invalid digest.');
        ensure(is_string($manifest['sources'][$source]['license'] ?? null), $source . ' has no license declaration.');
    }
    ensure(($manifest['sources']['php']['version'] ?? null) === EXPECTED_PHP_VERSION, 'Runtime manifest PHP source version is not the qualified version.');

    ensure(is_array($manifest['files']) && $manifest['files'] !== [] && !array_is_list($manifest['files']), 'Runtime manifest has no package file inventory.');
    if (str_starts_with($target, 'windows-')) {
        ensure(isset($manifest['files']['licenses/microsoft-vc143-runtime-NOTICE.txt']), 'The Windows package inventory omits the Microsoft VC143 runtime notice.');
    } else {
        ensure(!isset($manifest['files']['licenses/microsoft-vc143-runtime-NOTICE.txt']), 'A non-Windows package inventories the Microsoft VC143 runtime notice.');
    }
    $declaredPaths = array_keys($manifest['files']);
    $sortedDeclaredPaths = $declaredPaths;
    sort($sortedDeclaredPaths, SORT_STRING);
    ensure($declaredPaths === $sortedDeclaredPaths, 'Runtime manifest file keys are not sorted.');
    foreach ($manifest['files'] as $relative => $metadata) {
        ensure(
            is_string($relative)
            && preg_match('#^[A-Za-z0-9._+/-]+$#D', $relative) === 1
            && !str_starts_with($relative, '/')
            && !str_contains($relative, '\\')
            && !in_array('.', explode('/', $relative), true)
            && !in_array('..', explode('/', $relative), true),
            'Runtime manifest contains an unsafe file path.',
        );
        ensure($relative !== 'runtime-manifest.json' && !str_ends_with($relative, '.sha256'), 'Runtime manifest inventories an excluded file: ' . $relative);
        ensure(is_array($metadata) && array_keys($metadata) === ['bytes', 'sha256'], 'File inventory metadata has missing, unknown, or unordered fields: ' . $relative);
        ensure(is_int($metadata['bytes']) && $metadata['bytes'] >= 0, 'File inventory has an invalid byte count: ' . $relative);
        ensure(is_string($metadata['sha256']) && preg_match('/^[0-9a-f]{64}$/D', $metadata['sha256']) === 1, 'File inventory has an invalid SHA-256: ' . $relative);
    }
    foreach ($manifest['sources'] as $source => $metadata) {
        ensure(is_array($metadata), 'Runtime manifest source metadata is invalid: ' . $source);
        $licenseFile = $metadata['licenseFile'] ?? null;
        ensure(
            is_string($licenseFile)
            && preg_match('#^licenses/[A-Za-z0-9._+-]+\.txt$#D', $licenseFile) === 1,
            'Runtime manifest source has no safe packaged license path: ' . $source,
        );
        ensure(isset($manifest['files'][$licenseFile]), 'Runtime manifest source license is absent from the package inventory: ' . $source);
    }

    $ini = readFileStrict($root . DIRECTORY_SEPARATOR . 'php.ini');
    ensure(preg_match('#(?:[A-Za-z]:[\\\\/]|/(?:Users|home|root|workspace|build|private|tmp)/)#i', $ini) !== 1, 'php.ini contains an absolute build or private path.');
    ensure(preg_match('#(?:[A-Za-z]:[\\\\/]|/(?:Users|home|root|workspace|build|private|tmp)/)#i', json_encode($manifest, JSON_THROW_ON_ERROR)) !== 1, 'Runtime manifest leaks an absolute build or private path.');

    $rootReal = realpath($root);
    ensure($rootReal !== false, 'Could not resolve package root.');
    $actualFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        ensure(!$entry->isLink(), 'Package contains a symbolic link: ' . $entry->getPathname());
        ensure($entry->isFile(), 'Package contains an unexpected filesystem entry: ' . $entry->getPathname());
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($rootReal) + 1));
        ensure(!preg_match('#(?:^|[\\\\/])(?:\.git|build|cache|downloads|sources|tests?)(?:[\\\\/]|$)#i', $relative), 'Package contains undeclared build material: ' . $relative);
        ensure(!str_ends_with(strtolower($relative), '.part'), 'Package contains an incomplete file: ' . $relative);
        ensure(!str_ends_with(strtolower($relative), '.sha256'), 'Package contains an internal checksum sidecar: ' . $relative);
        if ($relative === 'runtime-manifest.json') {
            continue;
        }
        $size = $entry->getSize();
        $hash = hash_file('sha256', $entry->getPathname());
        ensure($hash !== false, 'Could not hash packaged file: ' . $relative);
        $actualFiles[$relative] = ['bytes' => $size, 'sha256' => $hash];
    }
    ksort($actualFiles, SORT_STRING);
    ensure($manifest['files'] === $actualFiles, 'Runtime manifest file inventory does not exactly match the package.');
}

function configuredCaFile(string $directive, string $packageRoot): string
{
    $value = ini_get($directive);
    ensure(is_string($value) && $value !== '', $directive . ' is not configured.');
    $path = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $value) === 1
        ? $value
        : $packageRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);
    ensure(is_file($path), $directive . ' does not resolve to a readable file.');
    return (string) realpath($path);
}

function qualifyLevelDb(string $temporaryRoot): void
{
    foreach (['LEVELDB_ZLIB_RAW_COMPRESSION', 'LEVELDB_ZSTD_COMPRESSION'] as $constant) {
        ensure(defined($constant), 'Bedrock compression constant is unavailable: ' . $constant);
    }
    ensure(constant('LEVELDB_ZLIB_RAW_COMPRESSION') === 4, 'Raw-DEFLATE compression constant is not Bedrock type 4.');
    ensure(constant('LEVELDB_ZSTD_COMPRESSION') === 3, 'Zstandard compression constant is not Bedrock type 3.');
    $path = $temporaryRoot . DIRECTORY_SEPARATOR . 'leveldb';
    $database = new LevelDB($path, ['create_if_missing' => true]);
    $database->put("plain\0key", "plain\0value");
    ensure($database->get("plain\0key") === "plain\0value", 'LevelDB CRUD did not preserve binary data.');

    $batch = new LevelDBWriteBatch();
    $batch->put('batch-a', 'one');
    $batch->put('batch-b', 'two');
    $batch->delete("plain\0key");
    $database->write($batch, ['sync' => true]);
    ensure($database->get('batch-a') === 'one' && $database->get('batch-b') === 'two', 'LevelDB batch write failed.');
    ensure($database->get("plain\0key") === false, 'LevelDB batch delete failed.');

    $helper = __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'leveldb-lock-probe.php';
    [$status, $stdout, $stderr] = runProcess(phpCommand([$helper, $path]));
    ensure($status === 0, 'LevelDB did not enforce its process lock: ' . trim($stdout . ' ' . $stderr));

    unset($database);
    gc_collect_cycles();
    $database = new LevelDB($path, ['create_if_missing' => false]);
    ensure($database->get('batch-a') === 'one', 'LevelDB value did not survive close and reopen.');
    $database->delete('batch-a', ['sync' => true]);
    ensure($database->get('batch-a') === false, 'LevelDB delete failed after reopen.');
    unset($database);
    gc_collect_cycles();

    foreach ([
        'raw-zlib' => constant('LEVELDB_ZLIB_RAW_COMPRESSION'),
        'zstd' => constant('LEVELDB_ZSTD_COMPRESSION'),
    ] as $name => $compression) {
        $compressedPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'leveldb-' . $name;
        $database = new LevelDB($compressedPath, [
            'create_if_missing' => true,
            'compression' => $compression,
            'write_buffer_size' => 64 * 1024,
        ]);
        $value = str_repeat('Bedriox-' . $name . '-', 16_384);
        $database->put('compressed', $value, ['sync' => true]);
        unset($database);
        gc_collect_cycles();
        $database = new LevelDB($compressedPath, ['create_if_missing' => false]);
        ensure($database->get('compressed') === $value, 'LevelDB ' . $name . ' value did not survive reopen.');
        unset($database);
        gc_collect_cycles();
    }
}

function qualifyIndependentLevelDbFixtures(string $temporaryRoot): void
{
    $repositoryRoot = dirname(__DIR__);
    $fixtureRoot = $repositoryRoot . DIRECTORY_SEPARATOR . 'fixtures';
    $metadata = readJsonObject($fixtureRoot . DIRECTORY_SEPARATOR . 'metadata.json');
    ensure(array_keys($metadata) === ['schemaVersion', 'corpus', 'sources', 'generators', 'fixtures'], 'LevelDB fixture metadata has missing or unknown fields.');
    ensure($metadata['schemaVersion'] === 2, 'LevelDB fixture metadata schema is unsupported.');

    $corpus = $fixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $metadata['corpus']['path']);
    ensure(is_file($corpus), 'LevelDB fixture corpus is missing.');
    ensure(filesize($corpus) === $metadata['corpus']['bytes'], 'LevelDB fixture corpus byte count differs from metadata.');
    ensure(hash_file('sha256', $corpus) === $metadata['corpus']['sha256'], 'LevelDB fixture corpus digest differs from metadata.');
    $records = LevelDbFixtureCorpus::read($corpus);
    ensure(count($records) === $metadata['corpus']['entries'], 'LevelDB fixture corpus entry count differs from metadata.');

    $expected = [
        'none' => [
            'path' => 'data/leveldb-none-v1.tar',
            'bytes' => 120832,
            'sha256' => 'bfd643a66d952d2c43b1fcfdd5d843b9df3188d55389eb242424f17fce19ebe2',
            'types' => [0],
        ],
        'raw-zlib' => [
            'path' => 'data/leveldb-raw-zlib-v1.tar',
            'bytes' => 70144,
            'sha256' => '272f8db913fb2ec106206bb9fd23d864c44806ceea69fb577aaeb4dfb087e688',
            'types' => [0, 4],
        ],
        'zstd' => [
            'path' => 'data/leveldb-zstd-v1.tar',
            'bytes' => 69632,
            'sha256' => 'a265324bdc61ebdc143705c1625b8c71a0b4affcbe36d99caa92dfcbc2611668',
            'types' => [0, 3],
        ],
    ];
    ensure(array_column($metadata['fixtures'], 'id') === array_keys($expected), 'LevelDB fixture set or order differs from the qualified set.');

    foreach ($metadata['fixtures'] as $fixture) {
        $identity = $expected[$fixture['id']];
        ensure($fixture['path'] === $identity['path'], "LevelDB {$fixture['id']} fixture path differs from the qualified path.");
        ensure($fixture['bytes'] === $identity['bytes'], "LevelDB {$fixture['id']} fixture byte count differs from the qualified value.");
        ensure($fixture['sha256'] === $identity['sha256'], "LevelDB {$fixture['id']} fixture digest differs from the qualified value.");
        ensure($fixture['compressionTypes'] === $identity['types'], "LevelDB {$fixture['id']} compression types differ from the qualified set.");

        $archive = $fixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fixture['path']);
        ensure(is_file($archive) && filesize($archive) === $identity['bytes'], "LevelDB {$fixture['id']} fixture archive is missing or has the wrong size.");
        ensure(hash_file('sha256', $archive) === $identity['sha256'], "LevelDB {$fixture['id']} fixture archive digest does not match.");
        $databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'independent-leveldb-' . $fixture['id'];
        UstarArchive::extract($archive, $databasePath);
        $inspection = LevelDbTableInspector::inspectDatabase($databasePath);
        ensure($inspection['compressionTypes'] === $identity['types'], "LevelDB {$fixture['id']} fixture physical compression tags do not match.");

        $database = new LevelDB(
            $databasePath,
            ['create_if_missing' => false, 'paranoid_checks' => true],
            ['verify_check_sum' => true, 'fill_cache' => false],
        );
        foreach ($records as $record) {
            ensure(
                $database->get($record['key'], ['verify_check_sum' => true, 'fill_cache' => false]) === $record['value'],
                "LevelDB {$fixture['id']} fixture lookup returned the wrong value.",
            );
        }
        ensure($database->get('missing-fixture-key', ['verify_check_sum' => true]) === false, "LevelDB {$fixture['id']} missing-key lookup did not return false.");
        $index = 0;
        $iterator = $database->getIterator(['verify_check_sum' => true, 'fill_cache' => false]);
        foreach ($iterator as $key => $value) {
            ensure(
                isset($records[$index]) && $key === $records[$index]['key'] && $value === $records[$index]['value'],
                "LevelDB {$fixture['id']} iteration differs at entry $index.",
            );
            $index++;
        }
        ensure($index === count($records), "LevelDB {$fixture['id']} iteration returned the wrong entry count.");
        unset($iterator, $database);
        gc_collect_cycles();

        $reopened = new LevelDB(
            $databasePath,
            ['create_if_missing' => false, 'paranoid_checks' => true],
            ['verify_check_sum' => true, 'fill_cache' => false],
        );
        ensure($reopened->get($records[0]['key'], ['verify_check_sum' => true]) === $records[0]['value'], "LevelDB {$fixture['id']} did not release its process lock cleanly.");
        unset($reopened);
        gc_collect_cycles();
    }
}

/** @return array{resource|false, int, string, string} */
function openSslTlsAttempt(string $host, int $port, string $peerName): array
{
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (($severity & E_WARNING) !== 0) {
            $warnings[] = $message;
            return true;
        }
        return false;
    });
    try {
        $errorCode = 0;
        $errorMessage = '';
        $stream = stream_socket_client(
            'tls://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $peerName,
                'SNI_enabled' => true,
            ]]),
        );
    } finally {
        restore_error_handler();
    }

    return [$stream, $errorCode, $errorMessage, implode("\n", $warnings)];
}

function ensureOpenSslRejected(string $host, int $port, string $peerName, string $reason): void
{
    [$stream, $errorCode, $errorMessage, $warnings] = openSslTlsAttempt($host, $port, $peerName);
    if (is_resource($stream)) {
        fclose($stream);
        throw new RuntimeException('OpenSSL accepted a TLS peer that should fail ' . $reason . ' verification.');
    }
    $evidence = trim($warnings . "\n" . $errorMessage);
    $pattern = $reason === 'hostname'
        ? '/(?:did not match expected|subject name.*(?:does not match|mismatch)|hostname mismatch|CN_NO_MATCH|target principal name)/i'
        : '/(?:certificate verify failed|self[- ]signed|unknown ca|unable to get (?:local )?issuer)/i';
    ensure(
        preg_match($pattern, $evidence) === 1,
        sprintf('OpenSSL %s negative did not prove verification rejection (code %d): %s', $reason, $errorCode, $evidence),
    );
}

function ensureCurlRejected(string $url, string $reason): void
{
    $handle = curl_init($url);
    ensure($handle !== false, 'Could not initialize negative cURL request.');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $result = curl_exec($handle);
    $errorCode = curl_errno($handle);
    $errorMessage = curl_error($handle);
    curl_close($handle);
    ensure($result === false, 'cURL accepted a TLS peer that should fail ' . $reason . ' verification.');
    ensure(
        $errorCode === CURL_PEER_VERIFICATION_ERROR,
        sprintf('cURL %s negative failed before certificate verification (code %d): %s', $reason, $errorCode, $errorMessage),
    );
    $pattern = $reason === 'hostname'
        ? '/(?:no alternative certificate subject name matches|subject name.*(?:does not match|mismatch)|CN_NO_MATCH|target principal name)/i'
        : '/(?:self[- ]signed|unknown ca|unable to get (?:local )?issuer|certificate verify failed)/i';
    ensure(preg_match($pattern, $errorMessage) === 1, 'cURL did not report the expected ' . $reason . ' rejection: ' . $errorMessage);
}

function networkTlsTest(): void
{
    $handle = curl_init('https://curl.se/');
    ensure($handle !== false, 'Could not initialize cURL.');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $result = curl_exec($handle);
    $errorCode = curl_errno($handle);
    $errorMessage = curl_error($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    ensure(
        is_string($result) && $status >= 200 && $status < 400,
        sprintf('Trusted cURL request failed before public-chain validation (code %d): %s', $errorCode, $errorMessage),
    );

    [$stream, $errorCode, $errorMessage, $warnings] = openSslTlsAttempt('curl.se', 443, 'curl.se');
    ensure(
        is_resource($stream),
        sprintf('Trusted OpenSSL stream failed before public-chain validation (code %d): %s', $errorCode, trim($warnings . "\n" . $errorMessage)),
    );
    fclose($stream);

    ensureCurlRejected('https://self-signed.badssl.com/', 'certificate');
    ensureCurlRejected('https://wrong.host.badssl.com/', 'hostname');
    ensureOpenSslRejected('self-signed.badssl.com', 443, 'self-signed.badssl.com', 'certificate');
    ensureOpenSslRejected('wrong.host.badssl.com', 443, 'wrong.host.badssl.com', 'hostname');
}

function reserveLoopbackPort(): int
{
    $errorCode = 0;
    $errorMessage = '';
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    ensure(is_resource($socket), 'Could not reserve a local TLS witness port: ' . $errorCode);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    ensure(is_string($address) && preg_match('/:(\d+)$/D', $address, $matches) === 1, 'Could not read local TLS witness port.');
    return (int) $matches[1];
}

function localUntrustedTlsTest(string $temporaryRoot): void
{
    $opensslConfig = $temporaryRoot . DIRECTORY_SEPARATOR . 'local-tls-openssl.cnf';
    $certificatePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'local-tls-certificate.pem';
    $privateKeyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'local-tls-private-key.pem';
    $readyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'local-tls-ready';
    $configuration = <<<'INI'
[req]
distinguished_name = subject
prompt = no
x509_extensions = server

[subject]
CN = localhost

[server]
subjectAltName = DNS:localhost
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
INI;
    ensure(file_put_contents($opensslConfig, $configuration, LOCK_EX) !== false, 'Could not create local TLS OpenSSL configuration.');

    $options = [
        'config' => $opensslConfig,
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'x509_extensions' => 'server',
    ];
    $privateKey = openssl_pkey_new($options);
    ensure($privateKey instanceof OpenSSLAsymmetricKey, 'Could not create temporary local TLS key.');
    $request = openssl_csr_new(['commonName' => 'localhost'], $privateKey, $options);
    ensure($request instanceof OpenSSLCertificateSigningRequest, 'Could not create temporary local TLS request.');
    $certificate = openssl_csr_sign($request, null, $privateKey, 1, $options, 1);
    ensure($certificate instanceof OpenSSLCertificate, 'Could not create temporary local TLS certificate.');
    ensure(openssl_x509_export($certificate, $certificatePem), 'Could not export temporary local TLS certificate.');
    ensure(openssl_pkey_export($privateKey, $privateKeyPem, null, $options), 'Could not export temporary local TLS key.');
    ensure(file_put_contents($certificatePath, $certificatePem, LOCK_EX) !== false, 'Could not write temporary local TLS certificate.');
    ensure(file_put_contents($privateKeyPath, $privateKeyPem, LOCK_EX) !== false, 'Could not write temporary local TLS key.');
    @chmod($privateKeyPath, 0600);

    $port = reserveLoopbackPort();
    $helper = __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'local-tls-server.php';
    $pipes = [];
    $process = proc_open(
        phpCommand([$helper, $certificatePath, $privateKeyPath, $readyPath, (string) $port]),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    ensure(is_resource($process), 'Could not start local TLS witness.');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 5.0;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            $status = proc_get_status($process);
            ensure($status['running'], 'Local TLS witness stopped before becoming ready.');
            usleep(10_000);
        }
        ensure(is_file($readyPath), 'Local TLS witness did not become ready.');
        ensureOpenSslRejected('127.0.0.1', $port, 'localhost', 'certificate');
        ensureCurlRejected('https://localhost:' . $port . '/', 'certificate');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        ensure($status === 0, 'Local TLS witness failed: ' . trim(($stdout ?: '') . ' ' . ($stderr ?: '')));
    } finally {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            foreach ([1, 2] as $pipe) {
                if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                    fclose($pipes[$pipe]);
                }
            }
            proc_close($process);
        }
    }
}

$arguments = $argv;
array_shift($arguments);
$packageRoot = null;
$allowMissingLevelDb = false;
$skipPackage = false;
$testNetworkTls = false;
foreach ($arguments as $argument) {
    if ($argument === '--allow-missing-leveldb') {
        $allowMissingLevelDb = true;
    } elseif ($argument === '--skip-package') {
        $skipPackage = true;
    } elseif ($argument === '--network-tls') {
        $testNetworkTls = true;
    } elseif ($packageRoot === null) {
        $packageRoot = $argument;
    } else {
        fwrite(STDERR, 'Unknown qualification argument: ' . $argument . PHP_EOL);
        exit(64);
    }
}
$packageRoot ??= getcwd() ?: dirname(PHP_BINARY);
$resolvedPackageRoot = realpath($packageRoot);
if ($resolvedPackageRoot === false || !is_dir($resolvedPackageRoot)) {
    fwrite(STDERR, 'Package root does not exist: ' . $packageRoot . PHP_EOL);
    exit(64);
}

$qualification = new Qualification();
$temporaryRoot = makeTemporaryDirectory();
try {
    $qualification->run('PHP 8.4 64-bit release runtime', static function (): void {
        ensure(PHP_VERSION === EXPECTED_PHP_VERSION, 'Expected PHP ' . EXPECTED_PHP_VERSION . ', got ' . PHP_VERSION . '.');
        ensure(PHP_INT_SIZE === 8, 'Runtime is not 64-bit.');
        ensure(!PHP_DEBUG, 'Runtime is a debug build.');
        ensure(PHP_SAPI === 'cli', 'Runtime SAPI is not CLI.');
    });

    $qualification->run('required extension set and ABI loading', static function (): void {
        $missing = array_values(array_filter(REQUIRED_EXTENSIONS, static fn(string $extension): bool => !extension_loaded($extension)));
        ensure($missing === [], 'Missing extensions: ' . implode(', ', $missing));
        ensure(extension_loaded('Zend OPcache'), 'Zend OPcache is not loaded.');
        $cacheRoot = getenv('BEDRIOX_RUNTIME_CACHE');
        ensure(is_string($cacheRoot) && $cacheRoot !== '', 'BEDRIOX_RUNTIME_CACHE is not configured.');
        $expectedOpcodeCache = realpath($cacheRoot . DIRECTORY_SEPARATOR . 'opcache');
        $configuredOpcodeCache = ini_get('opcache.file_cache');
        ensure($expectedOpcodeCache !== false, 'The external opcode cache directory is missing.');
        ensure(is_string($configuredOpcodeCache) && realpath($configuredOpcodeCache) === $expectedOpcodeCache, 'OPcache does not use the external runtime cache.');
        ensure(ini_get('opcache.file_cache_fallback') === '1', 'Windows OPcache file-cache fallback is disabled.');
        ensure(class_exists(SQLite3::class) && class_exists(ZipArchive::class), 'Required extension classes are unavailable.');
    });

    $qualification->run('signed PHAR creation and execution', static function () use ($temporaryRoot): void {
        $phar = $temporaryRoot . DIRECTORY_SEPARATOR . 'qualification.phar';
        $builder = __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'build-signed-phar.php';
        [$buildStatus, , $buildError] = runProcess([PHP_BINARY, '-n', '-d', 'phar.readonly=0', $builder, $phar]);
        ensure($buildStatus === 0, 'Signed PHAR creation failed: ' . trim($buildError));
        ensure((new Phar($phar))->getSignature()['hash_type'] === 'SHA-256', 'PHAR does not have a SHA-256 signature.');
        [$status, $stdout, $stderr] = runProcess(phpCommand([$phar]));
        ensure($status === 0 && $stdout === "Bedriox signed PHAR passed.\n" && $stderr === '', 'Signed PHAR execution failed.');
    });

    $qualification->run('SQLite transaction, rollback, commit, and reopen', static function () use ($temporaryRoot): void {
        $path = $temporaryRoot . DIRECTORY_SEPARATOR . 'qualification.sqlite3';
        $database = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        ensure($database->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)'), 'Could not create SQLite table.');
        ensure($database->exec("BEGIN; INSERT INTO records(value) VALUES ('discard'); ROLLBACK;"), 'SQLite rollback transaction failed.');
        ensure($database->querySingle('SELECT COUNT(*) FROM records') === 0, 'SQLite rollback was not honored.');
        ensure($database->exec("BEGIN; INSERT INTO records(value) VALUES ('persisted'); COMMIT;"), 'SQLite commit transaction failed.');
        $database->close();
        $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
        ensure($database->querySingle('SELECT value FROM records') === 'persisted', 'SQLite value did not survive reopen.');
        $database->close();
    });

    $qualification->run('ZIP create, close, reopen, and extract', static function () use ($temporaryRoot): void {
        $archivePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'qualification.zip';
        $extractPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'zip-output';
        ensure(mkdir($extractPath, 0700), 'Could not create ZIP extraction directory.');
        $archive = new ZipArchive();
        ensure($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'Could not create ZIP archive.');
        ensure($archive->addFromString('nested/value.txt', "zip-qualified\n"), 'Could not add ZIP entry.');
        ensure($archive->close(), 'Could not close ZIP archive.');
        ensure($archive->open($archivePath) === true, 'Could not reopen ZIP archive.');
        ensure($archive->numFiles === 1 && $archive->getFromName('nested/value.txt') === "zip-qualified\n", 'ZIP content changed after reopen.');
        ensure($archive->extractTo($extractPath), 'Could not extract ZIP archive.');
        $archive->close();
        ensure(readFileStrict($extractPath . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'value.txt') === "zip-qualified\n", 'Extracted ZIP content is wrong.');
    });

    $qualification->run('GMP arbitrary precision arithmetic', static function (): void {
        $left = gmp_init('340282366920938463463374607431768211507', 10);
        $right = gmp_init('18446744073709551629', 10);
        ensure(gmp_strval(gmp_mul($left, $right)) === '6277101735386680768259460193179866442067009288836208394903', 'GMP multiplication result is wrong.');
    });

    $qualification->run('UDP IPv4 loopback', static function (): void {
        $receiver = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        ensure($receiver instanceof Socket && $sender instanceof Socket, 'Could not create UDP sockets.');
        try {
            ensure(socket_bind($receiver, '127.0.0.1', 0), 'Could not bind UDP receiver.');
            $address = '';
            $port = 0;
            ensure(socket_getsockname($receiver, $address, $port) && $port > 0, 'Could not resolve UDP receiver port.');
            socket_set_option($receiver, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
            $payload = random_bytes(32);
            ensure(socket_sendto($sender, $payload, strlen($payload), 0, '127.0.0.1', $port) === strlen($payload), 'Could not send UDP datagram.');
            $received = '';
            $from = '';
            $fromPort = 0;
            ensure(socket_recvfrom($receiver, $received, 64, 0, $from, $fromPort) === strlen($payload), 'Could not receive UDP datagram.');
            ensure(hash_equals($payload, $received), 'UDP payload changed in transit.');
        } finally {
            socket_close($sender);
            socket_close($receiver);
        }
    });

    $qualification->run('OpenSSL and cURL CA configuration', static function () use ($resolvedPackageRoot): void {
        $opensslCa = configuredCaFile('openssl.cafile', $resolvedPackageRoot);
        $curlCa = configuredCaFile('curl.cainfo', $resolvedPackageRoot);
        ensure($opensslCa === $curlCa, 'OpenSSL and cURL use different CA bundles.');
        $contents = readFileStrict($opensslCa);
        ensure(str_contains($contents, '-----BEGIN CERTIFICATE-----') && strlen($contents) > 100_000, 'CA bundle is missing or implausibly small.');
        ensure(openssl_x509_read($contents) !== false, 'OpenSSL could not parse the first certificate in the CA bundle.');
    });

    $qualification->run('relocatable OpenSSL provider configuration', static function () use ($resolvedPackageRoot): void {
        $expectedConfiguration = realpath($resolvedPackageRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf');
        $activeConfiguration = getenv('OPENSSL_CONF');
        ensure($expectedConfiguration !== false, 'Packaged OpenSSL configuration is missing.');
        ensure(is_string($activeConfiguration) && $activeConfiguration !== '', 'OPENSSL_CONF is not configured.');
        ensure(realpath($activeConfiguration) === $expectedConfiguration, 'OPENSSL_CONF does not resolve inside the active runtime package.');

        $configuration = readFileStrict($expectedConfiguration);
        ensure(!preg_match('#(?:[A-Za-z]:[\\\\/]|/(?:Users|home|root|workspace|build|private|tmp)/)#i', $configuration), 'OpenSSL configuration contains an absolute build or private path.');
        foreach (['config_diagnostics = 1', 'openssl_conf = openssl_init', 'providers = providers', 'default = default_provider', 'activate = 1'] as $required) {
            ensure(str_contains($configuration, $required), 'OpenSSL configuration is missing: ' . $required);
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1',
        ]);
        ensure($key instanceof OpenSSLAsymmetricKey, 'The configured default provider could not create a Bedrock P-384 key.');
        $payload = 'bedriox-bedrock-handshake-witness';
        $signature = '';
        ensure(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA384), 'The configured default provider could not create an ES384 signature.');
        $details = openssl_pkey_get_details($key);
        ensure(is_array($details) && is_string($details['key'] ?? null), 'The configured default provider could not export the P-384 public key.');
        ensure(openssl_verify($payload, $signature, $details['key'], OPENSSL_ALGO_SHA384) === 1, 'The configured default provider could not verify an ES384 signature.');
    });

    $qualification->run('local untrusted TLS certificate rejection through default ini', static function () use ($temporaryRoot): void {
        localUntrustedTlsTest($temporaryRoot);
    });

    if ($testNetworkTls) {
        $qualification->run('public-chain and invalid network TLS behavior through default ini', static function (): void {
            networkTlsTest();
        });
    } else {
        $qualification->skip('public-chain and invalid network TLS behavior through default ini', 'run explicitly with --network-tls');
    }

    if (extension_loaded('leveldb')) {
        $qualification->run('LevelDB CRUD, batch, reopen, delete, and locking', static function () use ($temporaryRoot): void {
            qualifyLevelDb($temporaryRoot);
        });
        $qualification->run('independent LevelDB none, raw-DEFLATE, and zstd fixtures', static function () use ($temporaryRoot): void {
            qualifyIndependentLevelDbFixtures($temporaryRoot);
        });
    } elseif ($allowMissingLevelDb) {
        $qualification->skip('LevelDB CRUD, batch, reopen, delete, and locking', 'extension is absent and --allow-missing-leveldb was explicitly supplied');
        $qualification->skip('independent LevelDB none, raw-DEFLATE, and zstd fixtures', 'extension is absent and --allow-missing-leveldb was explicitly supplied');
    } else {
        $qualification->run('LevelDB CRUD, batch, reopen, delete, and locking', static function (): void {
            throw new RuntimeException('Required leveldb extension is not loaded.');
        });
        $qualification->run('independent LevelDB none, raw-DEFLATE, and zstd fixtures', static function (): void {
            throw new RuntimeException('Required leveldb extension is not loaded.');
        });
    }

    if ($skipPackage) {
        $qualification->skip('package layout, manifest, licenses, and path leakage', '--skip-package was explicitly supplied');
    } else {
        $qualification->run('package layout, manifest, licenses, and path leakage', static function () use ($resolvedPackageRoot): void {
            validatePackage($resolvedPackageRoot);
        });
        $qualification->run('relocation-friendly configuration', static function () use ($resolvedPackageRoot): void {
            $ini = readFileStrict($resolvedPackageRoot . DIRECTORY_SEPARATOR . 'php.ini');
            foreach (['extension_dir', 'curl.cainfo', 'openssl.cafile'] as $directive) {
                ensure(preg_match('/^\s*' . preg_quote($directive, '/') . '\s*=\s*["\']?([^"\'\r\n;]+)/mi', $ini, $matches) === 1, $directive . ' is absent from php.ini.');
                $value = trim($matches[1]);
                ensure(!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $value), $directive . ' uses an absolute path.');
                ensure(!str_contains($value, '..'), $directive . ' escapes the package root.');
            }
        });
    }
} finally {
    removeTemporaryTree($temporaryRoot, sys_get_temp_dir());
}

$qualification->finish();
