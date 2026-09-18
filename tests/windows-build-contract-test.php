<?php

declare(strict_types=1);

function windowsBuildEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/native-build.yml');
$orchestrator = file_get_contents($root . '/scripts/windows/native-build.ps1');
$driver = file_get_contents($root . '/scripts/windows/native-build.cmd');
windowsBuildEnsure(is_string($workflow) && is_string($orchestrator) && is_string($driver), 'Windows build contract inputs could not be read.');

$variables = [
    'BEDRIOX_NATIVE_PHP_SOURCE',
    'BEDRIOX_NATIVE_PHP_DEPS_SOURCE',
    'BEDRIOX_NATIVE_LEVELDB_SOURCE',
    'BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE',
    'BEDRIOX_NATIVE_WORK_ROOT',
    'BEDRIOX_NATIVE_INSTALL_ROOT',
    'BEDRIOX_NATIVE_CMAKE_EXE',
];
foreach ($variables as $variable) {
    windowsBuildEnsure(str_contains($orchestrator, $variable), "PowerShell orchestration does not set $variable.");
    windowsBuildEnsure(str_contains($driver, $variable), "Native command driver does not read $variable.");
}
windowsBuildEnsure(
    str_contains($orchestrator, '[Environment]::SetEnvironmentVariable($name, $null, \'Process\')'),
    'Native build environment variables are not cleared after the SDK task.',
);
windowsBuildEnsure(
    preg_match('/phpsdk-vs17-\{0\}\.bat/', $orchestrator) === 1,
    'Windows build does not use the pinned VS17 PHP SDK starter.',
);
windowsBuildEnsure(
    preg_match('/`"\$starter`" -t `"\$driver`"/', $orchestrator) === 1,
    'The PHP SDK task must receive only the driver path; task arguments are not forwarded reliably.',
);
windowsBuildEnsure(
    preg_match('/%~[1-9]/', $driver) !== 1,
    'The native driver still relies on positional arguments from phpsdk-starter.',
);

foreach ([
    '- windows-x86_64',
    'windows-x86_64) runner=windows-2022 ;;',
    'path: ${{ github.workspace }}/downloads',
    "if: runner.os != 'Windows'",
    "if: runner.os == 'Windows'",
    '.\\compile.ps1 -Target $env:TARGET -BootstrapPhp $bootstrapPhp',
] as $required) {
    windowsBuildEnsure(str_contains($workflow, $required), "Native workflow is missing: $required");
}
windowsBuildEnsure(!str_contains($workflow, '- windows-arm64'), 'Experimental Windows ARM64 is exposed by the native workflow.');

echo "Windows native build contract tests passed.\n";
