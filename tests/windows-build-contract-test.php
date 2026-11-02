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
$runtimeBuild = file_get_contents($root . '/scripts/windows/RuntimeBuild.psm1');
$driver = file_get_contents($root . '/scripts/windows/native-build.cmd');
$stage = file_get_contents($root . '/scripts/windows/stage-runtime.ps1');
windowsBuildEnsure(is_string($workflow) && is_string($orchestrator) && is_string($runtimeBuild) && is_string($driver) && is_string($stage), 'Windows build contract inputs could not be read.');
windowsBuildEnsure(
    preg_match('/\$input\b/i', $orchestrator . "\n" . $runtimeBuild) !== 1,
    'Windows build scripts must not shadow PowerShell\'s reserved $input automatic variable.',
);

$variables = [
    'BEDRIOX_NATIVE_PHP_SOURCE',
    'BEDRIOX_NATIVE_PHP_DEPS_SOURCE',
    'BEDRIOX_NATIVE_LEVELDB_SOURCE',
    'BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE',
    'BEDRIOX_NATIVE_LIBDATACHANNEL_SOURCE',
    'BEDRIOX_NATIVE_LIBJUICE_SOURCE',
    'BEDRIOX_NATIVE_PLOG_SOURCE',
    'BEDRIOX_NATIVE_USRSCTP_SOURCE',
    'BEDRIOX_NATIVE_WEBRTC_EXTENSION_SOURCE',
    'BEDRIOX_NATIVE_WORK_ROOT',
    'BEDRIOX_NATIVE_RUNTIME_STAGE',
    'BEDRIOX_NATIVE_CMAKE_EXE',
    'BEDRIOX_NATIVE_STAGE_SCRIPT',
    'BEDRIOX_NATIVE_REPOSITORY_ROOT',
    'BEDRIOX_NATIVE_INVOCATION_ROOT',
    'BEDRIOX_NATIVE_ARCHITECTURE',
];
foreach ($variables as $variable) {
    windowsBuildEnsure(str_contains($orchestrator, $variable), "PowerShell orchestration does not set $variable.");
    windowsBuildEnsure(str_contains($driver, $variable), "Native command driver does not read $variable.");
}
windowsBuildEnsure(
    str_contains($orchestrator, '[Environment]::SetEnvironmentVariable($name, $null, \'Process\')'),
    'Native build environment variables are not cleared after the SDK task.',
);
windowsBuildEnsure(str_contains($driver, '--with-php-build="..\deps"'), 'PHP configure is not bound to the neutral reviewed SDK dependency path.');
windowsBuildEnsure(!str_contains($driver, '--with-php-build="%PHP_BUILD%"'), 'PHP configure embeds its absolute dependency path.');
windowsBuildEnsure(str_contains($driver, '-VcRuntimeDirectory "%VC_RUNTIME_DIR%"'), 'Runtime staging does not receive the exact active Visual C++ runtime directory.');
windowsBuildEnsure(str_contains($stage, "'Microsoft.VC143.CRT'"), 'Runtime staging does not close Visual C++ runtime imports from the active VS17 toolchain.');
windowsBuildEnsure(str_contains($stage, "VC[\\\\/]Redist[\\\\/]MSVC"), 'Runtime staging does not constrain redistributable DLLs to the licensed Visual Studio Redist tree.');
windowsBuildEnsure(str_contains($stage, "'Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1'"), 'Runtime staging does not load the trusted in-box signature module explicitly.');
windowsBuildEnsure(str_contains($stage, 'Get-AuthenticodeSignature -LiteralPath $dll.FullName'), 'Visual C++ runtime candidates are not signature-verified.');
windowsBuildEnsure(str_contains($stage, "'(?:^|,\s*)O=Microsoft Corporation(?:,|$)'"), 'Visual C++ runtime signer validation does not require Microsoft Corporation as the exact organization.');
windowsBuildEnsure(str_contains($stage, '[System.Security.Cryptography.SHA256]::Create()'), 'Runtime staging does not use its shell-independent SHA-256 implementation.');
windowsBuildEnsure(!str_contains($stage, 'Get-FileHash'), 'Runtime staging depends on an auto-loaded hashing cmdlet.');
windowsBuildEnsure(
    preg_match('/phpsdk-vs17-\{0\}\.bat/', $orchestrator) === 1,
    'Windows build does not use the pinned VS17 PHP SDK starter.',
);
windowsBuildEnsure(
    preg_match('/`"\$starter`" -t `"\$driver`"/', $orchestrator) === 1,
    'The PHP SDK task must receive only the driver path; task arguments are not forwarded reliably.',
);
windowsBuildEnsure(str_contains($orchestrator, 'cmd.exe /d /s /c "`"$starter`" -t `"$driver`"" | Out-Host'), 'Native compiler output can contaminate the typed build result.');
windowsBuildEnsure(str_contains($orchestrator, '--format=zip | Out-Host'), 'Package qualification output can contaminate the typed build result.');
windowsBuildEnsure(
    preg_match('/%~[1-9]/', $driver) !== 1,
    'The native driver still relies on positional arguments from phpsdk-starter.',
);
windowsBuildEnsure(str_contains($driver, '--with-prefix=bedriox-runtime'), 'Windows PHP prefix is not neutral.');
windowsBuildEnsure(str_contains($driver, '--enable-zts'), 'Windows PHP does not explicitly preserve the reviewed thread-safe ABI.');
windowsBuildEnsure(str_contains($driver, '--with-iconv --with-libxml'), 'Windows PHP does not enable the pinned iconv prerequisite before libxml.');
foreach (['--with-dom', '--with-simplexml', '--with-xml', '--enable-zip', '--enable-zlib'] as $option) {
    windowsBuildEnsure(str_contains($driver, $option), "Windows PHP configure is missing the correct $option form.");
}
foreach (['--enable-dom', '--enable-simplexml', '--enable-xml ', '--with-zip', '--with-zlib', '--enable-hash', '--enable-json', '--with-config-file-path'] as $option) {
    windowsBuildEnsure(!str_contains($driver, $option), "Windows PHP configure retains the invalid $option form.");
}
windowsBuildEnsure(str_contains($driver, 'if errorlevel 1 (popd & exit /b 38)'), 'Windows PHP configure failures are not propagated.');
windowsBuildEnsure(str_contains($driver, 'nmake snap'), 'Windows does not produce the upstream runtime snapshot.');
windowsBuildEnsure(!str_contains($driver, 'nmake install'), 'Windows still installs the complete PHP development tree.');
windowsBuildEnsure(!str_contains($driver, '--with-prefix="%'), 'Windows embeds an invocation-specific PHP prefix.');
$expectedConfigure = 'call configure.bat --disable-all --enable-cli --enable-zts --enable-ctype --with-dom --enable-fileinfo --enable-filter --enable-mbstring --enable-opcache --enable-pdo --enable-phar --enable-session --with-simplexml --enable-sockets --enable-tokenizer --with-xml --enable-xmlreader --enable-xmlwriter --enable-zlib --enable-zip --with-prefix=bedriox-runtime --with-php-build="..\deps" --with-iconv --with-libxml --with-openssl --with-curl --with-sodium --with-sqlite3 --with-pdo-sqlite --with-gmp --with-leveldb=shared --enable-webrtc=shared --with-libdatachannel="..\deps"';
windowsBuildEnsure(str_contains($driver, $expectedConfigure), 'Windows PHP configure options differ from the reviewed PHP 8.4.25 contract.');

foreach ([
    'Get-PeImports',
    'Get-Command dumpbin.exe -CommandType Application',
    'PE import is neither packaged nor an allowlisted Windows system DLL',
    "-notin @('.exe', '.dll')",
    'Packaged PE contains a private build path',
    '1073741824',
    'Join-Path $RuntimeStage \'leveldb.dll\'',
] as $required) {
    windowsBuildEnsure(str_contains($stage, $required), "Windows runtime staging contract is missing: $required");
}
windowsBuildEnsure(!str_contains($stage, 'GetEnvironmentVariable("PATH")'), 'Windows runtime staging falls back to PATH for native libraries.');
windowsBuildEnsure(!str_contains($stage, 'PE debug data contains an absolute PDB path'), 'Runtime staging rejects signed Microsoft binaries for their producer PDB paths.');

foreach ([
    'for %%I in ("%PHP_SOURCE%\..\deps") do set "PHP_BUILD=%%~fI"',
    'if not exist "%ZLIB_HEADER%"',
    'if not exist "%ZLIB_LIBRARY%"',
    'if not exist "%ZSTD_HEADER%"',
    'if not exist "%ZSTD_LIBRARY%"',
    'if not exist "%PHP_BUILD%\include\iconv.h"',
    'for %%I in (libiconv_a.lib libiconv.lib iconv_a.lib iconv.lib)',
    'if not defined ICONV_LIBRARY',
    '-DBUILD_SHARED_LIBS=ON',
    'set "PHP_BUILD_CMAKE=%PHP_BUILD:\\=/%"',
    '"-DZLIB_INCLUDE_DIR:PATH=%PHP_BUILD_CMAKE%/include"',
    '"-DZLIB_LIBRARY:FILEPATH=%ZLIB_LIBRARY_CMAKE%"',
    '"-Dzstd_INCLUDE_DIRS:PATH=%PHP_BUILD_CMAKE%/include"',
    '"-Dzstd_LIBRARIES:FILEPATH=%ZSTD_LIBRARY_CMAKE%"',
    'findstr /L /X /C:"ZLIB_LIBRARY:FILEPATH=%ZLIB_LIBRARY_CMAKE%"',
    'findstr /L /X /C:"zstd_LIBRARIES:FILEPATH=%ZSTD_LIBRARY_CMAKE%"',
    'if not exist "%PHP_BUILD%\\bin\\leveldb.dll"',
    'if not exist "%PHP_BUILD%\\lib\\leveldb.lib"',
    '-LevelDbDll "%PHP_BUILD%\\bin\\leveldb.dll"',
    'dumpbin /nologo /dependents "%PHP_LEVELDB_DLL%"',
    'findstr /I /L /C:"leveldb.dll"',
    'dumpbin /nologo /dependents "%PHP_BIN%leveldb.dll"',
    'findstr /I /R "zlib.*\\.dll zstd.*\\.dll"',
] as $required) {
    windowsBuildEnsure(str_contains($driver, $required), "Windows LevelDB closure is missing: $required");
}
windowsBuildEnsure(
    !str_contains($driver, '-DBUILD_SHARED_LIBS=OFF'),
    'Windows still builds LevelDB as a static library.',
);
windowsBuildEnsure(
    !str_contains($driver, '-DCMAKE_PREFIX_PATH='),
    'Windows LevelDB discovery still permits broad prefix probing.',
);
windowsBuildEnsure(
    str_contains($driver, '--with-leveldb=shared'),
    'The PHP LevelDB extension is not built as a shared extension against the reviewed prefix.',
);
windowsBuildEnsure(!str_contains($driver, '--with-leveldb=shared,"%PHP_BUILD%"'), 'The LevelDB configure option embeds its absolute dependency path.');

foreach ([
    'xcopy "%LIBDATACHANNEL_SOURCE%\*" "%LIBDATACHANNEL_WORK%\"',
    'xcopy "%LIBJUICE_SOURCE%\*" "%LIBDATACHANNEL_WORK%\deps\libjuice\"',
    'xcopy "%PLOG_SOURCE%\*" "%LIBDATACHANNEL_WORK%\deps\plog\"',
    'xcopy "%USRSCTP_SOURCE%\*" "%LIBDATACHANNEL_WORK%\deps\usrsctp\"',
    '-DataChannelDll "%PHP_BUILD%\bin\datachannel.dll"',
    '-DNO_EXAMPLES=ON',
    '-DNO_TESTS=ON',
    '-DNO_MEDIA=ON',
    '-DNO_WEBSOCKET=ON',
    'set "CL=/experimental:deterministic /pathmap:%WORK_ROOT%=. %CL%"',
    'if not exist "%PHP_BUILD%\bin\datachannel.dll"',
    'if not exist "%RUNTIME_STAGE%\ext\php_webrtc.dll"',
] as $required) {
    windowsBuildEnsure(str_contains($driver, $required), "Windows NetherNet runtime closure is missing: $required");
}
windowsBuildEnsure(
    str_contains($driver, 'if not exist "%LIBDATACHANNEL_WORK%\deps" mkdir "%LIBDATACHANNEL_WORK%\deps" || exit /b 91'),
    'The Windows build must accept libdatachannel source trees that already contain their deps directory.',
);
windowsBuildEnsure(str_contains($stage, '$DataChannelDll'), 'Windows runtime staging does not admit the reviewed libdatachannel DLL.');

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
