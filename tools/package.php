<?php

declare(strict_types=1);

require_once __DIR__ . '/PackageBuilder.php';
require_once __DIR__ . '/PackageArchive.php';

function fail(string $message): never
{
    fwrite(STDERR, 'Packaging error: ' . $message . PHP_EOL);
    exit(1);
}

/** @return array{0: int, 1: string, 2: string} */
function run(array $command, ?array $environment = null): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start package subprocess.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
}

/** @return array<string, string> */
function qualificationEnvironment(string $runtimeRoot): array
{
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    $blocked = [
        'CURL_CA_BUNDLE',
        'OPENSSL_CONF',
        'OPENSSL_MODULES',
        'SSL_CERT_DIR',
        'SSL_CERT_FILE',
    ];
    foreach (array_keys($environment) as $name) {
        if (in_array(strtoupper((string) $name), $blocked, true)) {
            unset($environment[$name]);
        }
    }
    $environment['BEDRIOX_RUNTIME_ROOT'] = $runtimeRoot;
    return $environment;
}

$archivePath = null;
$candidateArchive = null;
$ownsArchive = false;
$ownsCandidateArchive = false;
$ownsChecksum = false;

try {
    $options = getopt('', [
        'repository-root:', 'target:', 'invocation-root:', 'source-root:', 'package-root:', 'package-parent:',
        'artifact-root:', 'ca-file:', 'compiler:', 'linker:', 'source-ids:', 'format:',
    ]);
    $required = ['repository-root', 'target', 'invocation-root', 'source-root', 'package-root', 'package-parent', 'artifact-root', 'ca-file', 'compiler', 'linker', 'source-ids', 'format'];
    foreach ($required as $name) {
        if (!isset($options[$name]) || !is_string($options[$name]) || $options[$name] === '') {
            throw new InvalidArgumentException('Missing required --' . $name . ' option.');
        }
    }

    $repositoryRoot = realpath(PackageBuilder::assertAbsolutePath($options['repository-root'], 'Repository root'));
    if ($repositoryRoot === false || !is_dir($repositoryRoot)) {
        throw new RuntimeException('Repository root does not exist.');
    }
    $target = $options['target'];
    PackageBuilder::assertTarget($target);
    $invocationRoot = realpath(PackageBuilder::assertAbsolutePath($options['invocation-root'], 'Invocation root'));
    if ($invocationRoot === false || !is_dir($invocationRoot)) {
        throw new RuntimeException('Invocation root does not exist.');
    }
    $sourceRoot = PackageBuilder::assertExistingDirectoryBelow($options['source-root'], $invocationRoot, 'Native source root');
    $packageParent = realpath(PackageBuilder::assertAbsolutePath($options['package-parent'], 'Package parent'));
    if ($packageParent === false || !is_dir($packageParent)) {
        throw new RuntimeException('Package parent does not exist.');
    }
    $packageRoot = PackageBuilder::assertAbsolutePath($options['package-root'], 'Package root');
    PackageBuilder::assertNewDirectoryBelow($packageRoot, $packageParent, 'Package root');
    PackageBuilder::assertExistingDirectoryBelow($packageParent, $invocationRoot, 'Package parent');
    $artifactRoot = realpath(PackageBuilder::assertAbsolutePath($options['artifact-root'], 'Artifact root'));
    if ($artifactRoot === false || !is_dir($artifactRoot)) {
        throw new RuntimeException('Artifact root does not exist.');
    }
    $caFile = realpath(PackageBuilder::assertAbsolutePath($options['ca-file'], 'CA bundle'));
    if ($caFile === false || !is_file($caFile)) {
        throw new RuntimeException('Pinned CA bundle does not exist.');
    }
    $format = $options['format'];
    if (!in_array($format, ['zip', 'tar.gz'], true)) {
        throw new InvalidArgumentException('Archive format must be zip or tar.gz.');
    }
    $sourceIds = explode(',', $options['source-ids']);
    if ($sourceIds === [] || array_values(array_unique($sourceIds)) !== $sourceIds) {
        throw new InvalidArgumentException('Source IDs must be a non-empty unique comma-separated list.');
    }
    foreach ($sourceIds as $sourceId) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $sourceId) !== 1) {
            throw new InvalidArgumentException('Invalid source ID: ' . $sourceId);
        }
    }

    PackageBuilder::assertNativeRuntimeLayout($sourceRoot, $target);
    PackageBuilder::copyTree($sourceRoot, $packageRoot);
    $executable = $packageRoot . DIRECTORY_SEPARATOR . (str_starts_with($target, 'windows-') ? 'php.exe' : 'php');
    if (!is_file($executable)) {
        throw new RuntimeException('Native stage does not contain the target PHP executable at its root.');
    }
    @chmod($executable, 0755);

    foreach (['certs', 'licenses'] as $directory) {
        $path = $packageRoot . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($path) && !mkdir($path, 0755, false)) {
            throw new RuntimeException('Could not create package ' . $directory . ' directory.');
        }
    }
    foreach (PackageBuilder::noticeFiles($target) as $sourceRelative => $destinationRelative) {
        $source = $repositoryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelative);
        $destination = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destinationRelative);
        if (!is_file($source) || !copy($source, $destination)) {
            throw new RuntimeException('Could not stage package notice: ' . $sourceRelative);
        }
    }
    $manifestPath = $repositoryRoot . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . 'dependencies.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !isset($manifest['sources']) || !is_array($manifest['sources'])) {
        throw new RuntimeException('Dependency manifest has no source inventory.');
    }
    if (!in_array('ca-bundle', $sourceIds, true)) {
        throw new RuntimeException('Exact source closure does not include the CA bundle.');
    }
    $caMetadata = $manifest['sources']['ca-bundle'] ?? null;
    $caHash = hash_file('sha256', $caFile);
    if (
        !is_array($caMetadata)
        || $caHash === false
        || !hash_equals((string) ($caMetadata['sha256'] ?? ''), $caHash)
        || filesize($caFile) !== ($caMetadata['bytes'] ?? null)
    ) {
        throw new RuntimeException('CA bundle does not match the immutable dependency manifest.');
    }
    if (!copy($caFile, $packageRoot . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem')) {
        throw new RuntimeException('Could not stage the pinned CA bundle.');
    }
    $sources = [];
    foreach ($sourceIds as $sourceId) {
        $source = $manifest['sources'][$sourceId] ?? null;
        if (!is_array($source) || !in_array($target, $source['targets'] ?? [], true)) {
            throw new RuntimeException('Source is absent or does not support target: ' . $sourceId);
        }
        $licenseRelative = $source['licenseFile'] ?? null;
        if (!is_string($licenseRelative) || preg_match('#^licenses/[A-Za-z0-9._+-]+\.txt$#D', $licenseRelative) !== 1) {
            throw new RuntimeException('Source has an unsafe license path: ' . $sourceId);
        }
        $licenseSource = $repositoryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $licenseRelative);
        $licenseDestination = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $licenseRelative);
        if (!is_file($licenseSource) || !copy($licenseSource, $licenseDestination)) {
            throw new RuntimeException('Could not stage source license: ' . $sourceId);
        }
        $metadata = [
            'sha256' => $source['sha256'],
            'license' => $source['license'],
            'licenseFile' => $licenseRelative,
        ];
        if (isset($source['version'])) {
            $metadata = ['version' => $source['version']] + $metadata;
        } elseif (isset($source['commit'])) {
            $metadata = ['commit' => $source['commit']] + $metadata;
        } else {
            throw new RuntimeException('Source has no immutable version: ' . $sourceId);
        }
        $sources[$sourceId] = $metadata;
    }
    ksort($sources, SORT_STRING);

    $template = file_get_contents($repositoryRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'php.ini-production');
    if ($template === false) {
        throw new RuntimeException('PHP configuration template is unreadable.');
    }
    $ini = PackageBuilder::renderIni($template, $packageRoot);
    if (file_put_contents($packageRoot . DIRECTORY_SEPARATOR . 'php.ini', $ini, LOCK_EX) === false) {
        throw new RuntimeException('Could not write packaged php.ini.');
    }

    [$infoStatus, $infoOutput, $infoError] = run([$executable, '-n', '-r', 'echo json_encode([PHP_VERSION, php_uname("m"), (bool) PHP_ZTS], JSON_THROW_ON_ERROR);']);
    if ($infoStatus !== 0) {
        throw new RuntimeException('Packaged PHP could not report its identity: ' . trim($infoOutput . ' ' . $infoError));
    }
    $identity = json_decode($infoOutput, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($identity) || count($identity) !== 3 || !is_string($identity[0]) || !is_string($identity[1]) || !is_bool($identity[2])) {
        throw new RuntimeException('Packaged PHP returned an invalid identity.');
    }
    $runtimeManifest = [
        'schemaVersion' => 1,
        'target' => $target,
        'architecture' => $identity[1],
        'phpVersion' => $identity[0],
        'threadSafe' => $identity[2],
        'compiler' => PackageBuilder::assertIdentity($options['compiler'], 'Compiler'),
        'linker' => PackageBuilder::assertIdentity($options['linker'], 'Linker'),
        'buildPolicy' => str_starts_with($target, 'windows-')
            ? ['optimized', 'native target', 'pinned dependencies']
            : ['optimized', 'position-independent', 'native target', 'pinned dependencies'],
        'sources' => $sources,
        'files' => PackageBuilder::inventory($packageRoot),
    ];
    $encoded = json_encode($runtimeManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($packageRoot . DIRECTORY_SEPARATOR . 'runtime-manifest.json', $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not write runtime manifest.');
    }

    $environment = qualificationEnvironment($packageRoot);
    [$qualificationStatus, $qualificationOutput, $qualificationError] = run([
        $executable, '-c', $packageRoot . DIRECTORY_SEPARATOR . 'php.ini',
        $repositoryRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'qualify.php',
        $packageRoot,
        '--network-tls',
    ], $environment);
    echo $qualificationOutput;
    if ($qualificationError !== '') {
        fwrite(STDERR, $qualificationError);
    }
    if ($qualificationStatus !== 0) {
        throw new RuntimeException('Packaged runtime qualification failed; no archive was created.');
    }

    $archiveName = 'bedriox-runtime-' . $target . ($format === 'zip' ? '.zip' : '.tar.gz');
    $archivePath = $artifactRoot . DIRECTORY_SEPARATOR . $archiveName;
    if (file_exists($archivePath) || is_link($archivePath) || file_exists($archivePath . '.sha256')) {
        throw new RuntimeException('Artifact or checksum already exists: ' . $archiveName);
    }
    $candidateArchive = $artifactRoot . DIRECTORY_SEPARATOR . 'bedriox-runtime-' . $target . '.partial-' . getmypid() . ($format === 'zip' ? '.zip' : '.tar.gz');
    if (file_exists($candidateArchive) || is_link($candidateArchive)) {
        throw new RuntimeException('Temporary artifact already exists.');
    }
    $ownsCandidateArchive = true;
    [$archiveStatus, $archiveOutput, $archiveError] = run([
        $executable, '-c', $packageRoot . DIRECTORY_SEPARATOR . 'php.ini',
        $repositoryRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'package-archive.php',
        $format, $packageRoot, $candidateArchive,
    ], $environment);
    if ($archiveStatus !== 0) {
        throw new RuntimeException('Archive creation failed: ' . trim($archiveOutput . ' ' . $archiveError));
    }

    $relocationParent = $invocationRoot . DIRECTORY_SEPARATOR . 'archive relocation with spaces';
    if (file_exists($relocationParent) || is_link($relocationParent) || !mkdir($relocationParent, 0755, false)) {
        throw new RuntimeException('Could not create the clean relocation test parent.');
    }
    $relocationDestination = $relocationParent . DIRECTORY_SEPARATOR . 'extracted runtime';
    [$verifyStatus, $verifyOutput, $verifyError] = run([
        $executable, '-c', $packageRoot . DIRECTORY_SEPARATOR . 'php.ini',
        $repositoryRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'package-verify.php',
        '--extract-to', $relocationDestination, $format, $candidateArchive, $packageRoot,
    ], $environment);
    if ($verifyStatus !== 0) {
        throw new RuntimeException('Archive verification or extraction failed: ' . trim($verifyOutput . ' ' . $verifyError));
    }
    $relocationRoot = $relocationDestination . DIRECTORY_SEPARATOR . 'bin';
    if (trim($verifyOutput) !== $relocationRoot) {
        throw new RuntimeException('Archive verifier returned an unexpected relocated runtime root.');
    }

    $relocatedExecutable = $relocationRoot . DIRECTORY_SEPARATOR . (str_starts_with($target, 'windows-') ? 'php.exe' : 'php');
    $relocatedIni = $relocationRoot . DIRECTORY_SEPARATOR . 'php.ini';
    $probe = $repositoryRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'relocation-probe.php';
    $relocatedEnvironment = $environment;
    $relocatedEnvironment['BEDRIOX_RUNTIME_ROOT'] = $relocationRoot;
    [$probeStatus, $probeOutput, $probeError] = run([
        $relocatedExecutable, '-c', $relocatedIni, $probe, $relocationRoot,
    ], $relocatedEnvironment);
    if ($probeStatus !== 0) {
        throw new RuntimeException('Relocated runtime probe failed: ' . trim($probeOutput . ' ' . $probeError));
    }

    $unsetEnvironment = $relocatedEnvironment;
    unset($unsetEnvironment['BEDRIOX_RUNTIME_ROOT']);
    [$unsetStatus, , $unsetError] = run([
        $relocatedExecutable, '-c', $relocatedIni, $probe, $relocationRoot,
    ], $unsetEnvironment);
    if ($unsetStatus === 0 || !str_contains($unsetError, 'BEDRIOX_RUNTIME_ROOT')) {
        throw new RuntimeException('Relocated runtime did not fail closed when BEDRIOX_RUNTIME_ROOT was unset.');
    }

    $wrongRoot = $relocationParent . DIRECTORY_SEPARATOR . 'wrong runtime root';
    if (!mkdir($wrongRoot, 0755, false)) {
        throw new RuntimeException('Could not create wrong-root relocation fixture.');
    }
    $wrongEnvironment = $relocatedEnvironment;
    $wrongEnvironment['BEDRIOX_RUNTIME_ROOT'] = $wrongRoot;
    [$wrongStatus, , $wrongError] = run([
        $relocatedExecutable, '-c', $relocatedIni, $probe, $relocationRoot,
    ], $wrongEnvironment);
    if ($wrongStatus === 0 || !str_contains($wrongError, 'BEDRIOX_RUNTIME_ROOT')) {
        throw new RuntimeException('Relocated runtime did not fail closed with the wrong BEDRIOX_RUNTIME_ROOT.');
    }

    [$relocatedStatus, $relocatedOutput, $relocatedError] = run([
        $relocatedExecutable, '-c', $relocatedIni,
        $repositoryRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'qualify.php',
        $relocationRoot,
    ], $relocatedEnvironment);
    echo $relocatedOutput;
    if ($relocatedError !== '') {
        fwrite(STDERR, $relocatedError);
    }
    if ($relocatedStatus !== 0) {
        throw new RuntimeException('Extracted relocated runtime qualification failed; no artifact was published.');
    }

    PackageArchive::publish($candidateArchive, $archivePath);
    $ownsArchive = true;
    $ownsCandidateArchive = false;
    $candidateArchive = null;
    PackageArchive::writeChecksum($archivePath, $archivePath . '.sha256', $archiveName);
    $ownsChecksum = true;
    echo 'Qualified artifact: ' . $archivePath . PHP_EOL;
} catch (Throwable $exception) {
    if ($ownsCandidateArchive && is_string($candidateArchive)) {
        @unlink($candidateArchive);
    }
    if ($ownsArchive && is_string($archivePath)) {
        if ($ownsChecksum) {
            @unlink($archivePath . '.sha256');
        }
        @unlink($archivePath);
    }
    fail($exception->getMessage());
}
