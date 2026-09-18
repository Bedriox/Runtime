<?php

declare(strict_types=1);

function unixBuildEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$compiler = file_get_contents($root . '/scripts/unix/compile-native.sh');
$preflight = file_get_contents($root . '/scripts/unix/preflight.sh');
unixBuildEnsure(is_string($compiler) && is_string($preflight), 'Unix build contract inputs could not be read.');

foreach ([
    'runtime_prefix=/opt/bedriox-runtime',
    'make install INSTALL_ROOT="$install_stage"',
    '-g0',
    '-ffile-prefix-map=',
    '-fdebug-prefix-map=',
    '-fmacro-prefix-map=',
    'CFLAGS="$runtime_cflags" CXXFLAGS="$runtime_cflags" ./configure',
    '-DCMAKE_C_FLAGS="$runtime_cflags"',
    '-DCMAKE_CXX_FLAGS="$runtime_cflags"',
    '--openssldir="$runtime_prefix/ssl"',
    'make install_sw DESTDIR="$openssl_stage"',
    'cp -R -p -- "$openssl_install_root/." "$prefix/"',
    'OpenSSL metadata does not contain the neutral runtime prefix.',
    './configure --prefix="$runtime_prefix" --disable-rpath',
    './configure --disable-rpath --with-php-config="$build_php_config"',
    '[ -f "$install_root/include/php/main/php.h" ]',
    '"$("$build_php_config" --prefix)" = "$install_root"',
    '"$build_phpize" --version',
    'readelf -d "$binary" > "$dynamic_report"',
    "grep -E '(RPATH|RUNPATH)'",
    "grep -q 'LC_RPATH'",
    'grep -F "$private_root" "$strings_report"',
    '--source-root="$runtime_stage"',
] as $required) {
    unixBuildEnsure(str_contains($compiler, $required), 'Unix native build is missing hardening contract: ' . $required);
}

foreach (['phpize', 'php-config', 'phar.phar'] as $developmentFile) {
    unixBuildEnsure(
        !str_contains($compiler, 'cp -- "$package_bin/' . $developmentFile . '" "$runtime_stage/'),
        'Unix runtime stage includes development-only file: ' . $developmentFile,
    );
}

foreach (['runtime_require_command readelf', 'runtime_require_command strings', 'xcrun --find otool', 'xcrun --find strings'] as $required) {
    unixBuildEnsure(str_contains($preflight, $required), 'Unix preflight is missing inspection tool: ' . $required);
}

echo "Unix native build contract tests passed.\n";
