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
$workflow = file_get_contents($root . '/.github/workflows/native-build.yml');
unixBuildEnsure(is_string($compiler) && is_string($preflight) && is_string($workflow), 'Unix build contract inputs could not be read.');
unixBuildEnsure(str_contains($workflow, "if: runner.os != 'macOS'\n        uses: shivammathur/setup-php@"), 'macOS still uses the failing generic PHP bootstrap action.');
unixBuildEnsure(str_contains($workflow, 'brew install php@8.4 autoconf automake bison cmake libtool pkg-config re2c'), 'macOS does not install its PHP 8.4 bootstrap with the native Homebrew toolchain.');
unixBuildEnsure(str_contains($workflow, 'echo "$(brew --prefix php@8.4)/bin" >> "$GITHUB_PATH"'), 'macOS does not expose the pinned PHP 8.4 bootstrap to later build steps.');
unixBuildEnsure(str_contains($workflow, 'PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4'), 'macOS does not verify its PHP 8.4 bootstrap before compilation.');

foreach ([
    'runtime_prefix=/opt/bedriox-runtime',
    'make install INSTALL_ROOT="$install_stage"',
    'make_install_autoconf libiconv',
    '-g0',
    '-ffile-prefix-map=',
    '-fdebug-prefix-map=',
    '-fmacro-prefix-map=',
    'CFLAGS="$runtime_cflags" CXXFLAGS="$runtime_cflags" ./configure',
    '-DCMAKE_C_FLAGS="$runtime_cflags"',
    '-DCMAKE_CXX_FLAGS="$runtime_cflags"',
    'openssl_cflags="-O2 -g0 -fPIC"',
    'CFLAGS="$openssl_cflags" ./Configure',
    'make_install_autoconf libxml2 --sysconfdir="$runtime_prefix/etc"',
    '--openssldir="$runtime_prefix/ssl"',
    'make install_sw DESTDIR="$openssl_stage"',
    'cp -R -p -- "$openssl_install_root/." "$prefix/"',
    'OpenSSL metadata does not contain the neutral runtime prefix.',
    './configure --prefix="$runtime_prefix" --disable-rpath',
    'php_dependency_root=../prefix',
    'PKG_CONFIG_PATH="$php_pkg_config_path" pkg-config --define-variable=prefix="$php_dependency_root" --cflags libzip',
    'PKG_CONFIG_PATH="$php_pkg_config_path" pkg-config --define-variable=prefix="$php_dependency_root" --static --libs libzip',
    '--define-variable=libdir="$php_dependency_root/lib" --define-variable=includedir="$php_dependency_root/include"',
    '--static --libs libcurl',
    'str_replace($argv[2], $argv[3], $argv[1])',
    "-r 'echo str_replace(\$argv[2], \$argv[3], \$argv[1]);' -- \"\$CURL_CFLAGS\"",
    'relocated libcurl flags retain the private dependency prefix',
    'for framework in CoreFoundation CoreServices SystemConfiguration',
    'pinned macOS libcurl metadata omits framework: $framework',
    "php_platform_ldflags='-framework CoreFoundation -framework CoreServices -framework SystemConfiguration'",
    'PKG_CONFIG_PATH="$php_pkg_config_path" CPPFLAGS="-I$php_dependency_root/include" LDFLAGS="-L$php_dependency_root/lib $php_platform_ldflags"',
    'CURL_CFLAGS="$CURL_CFLAGS" CURL_LIBS="$CURL_LIBS"',
    '--with-iconv="$php_dependency_root"',
    'grep -F "$private_root" main/build-defs.h',
    'PHP build definitions contain a private $private_root_kind path',
    'sanitized PHP build-definition witness:',
    './configure --with-php-config="$build_php_config" --with-leveldb="$prefix"',
    'leveldb_link_closure="$prefix/lib/libleveldb.a $prefix/lib/libzstd.a $prefix/lib/libz.a $leveldb_cxx_runtime"',
    'make -j"$jobs" LEVELDB_SHARED_LIBADD="$leveldb_link_closure"',
    'make install LEVELDB_SHARED_LIBADD="$leveldb_link_closure"',
    'PHP LevelDB dependency prefix contains a shared LevelDB library',
    'LevelDB extension retains a dynamic storage dependency',
    '[ -f "$install_root/include/php/main/php.h" ]',
    '"$("$build_php_config" --prefix)" = "$install_root"',
    '"$build_phpize" --version',
    'readelf -d "$binary" > "$dynamic_report"',
    "grep -E '(RPATH|RUNPATH)'",
    "grep -q 'LC_RPATH'",
    'for private_root_kind in build source repository download',
    'grep -F "$private_root" "$strings_report"',
    'sanitized private path witness:',
    'str_replace($root, "<private-"',
    'runtime binary contains a private $private_root_kind path',
    '--source-root="$runtime_stage"',
] as $required) {
    unixBuildEnsure(str_contains($compiler, $required), 'Unix native build is missing hardening contract: ' . $required);
}

$opensslBlockStart = strpos($compiler, 'openssl_source=$(source_path openssl)');
$opensslBlockEnd = strpos($compiler, 'openssl_install_root=');
unixBuildEnsure($opensslBlockStart !== false && $opensslBlockEnd !== false && $opensslBlockEnd > $opensslBlockStart, 'OpenSSL build block could not be isolated.');
$opensslBuildBlock = substr($compiler, $opensslBlockStart, $opensslBlockEnd - $opensslBlockStart);
unixBuildEnsure(
    !str_contains($opensslBuildBlock, 'CFLAGS="$runtime_cflags" ./Configure'),
    'OpenSSL build information still receives private prefix-map operands.',
);
unixBuildEnsure(
    !str_contains($compiler, 'make_install_autoconf libxml2 --without-python'),
    'libxml2 still receives the private dependency prefix as its default sysconfdir.',
);

foreach (['phpize', 'php-config', 'phar.phar'] as $developmentFile) {
    unixBuildEnsure(
        !str_contains($compiler, 'cp -- "$package_bin/' . $developmentFile . '" "$runtime_stage/'),
        'Unix runtime stage includes development-only file: ' . $developmentFile,
    );
}

$phpBlockStart = strpos($compiler, 'php_source=$(source_path php)');
$phpBlockEnd = strpos($compiler, 'leveldb_extension_source=$(source_path php-leveldb)');
unixBuildEnsure($phpBlockStart !== false && $phpBlockEnd !== false && $phpBlockEnd > $phpBlockStart, 'PHP build block could not be isolated.');
$phpBuildBlock = substr($compiler, $phpBlockStart, $phpBlockEnd - $phpBlockStart);
foreach (['--with-openssl="$prefix"', '--with-curl="$prefix"', '--with-zlib="$prefix"', '--with-gmp="$prefix"', '--with-sodium="$prefix"', '--with-sqlite3="$prefix"', '--with-pdo-sqlite="$prefix"'] as $privateConfigureArgument) {
    unixBuildEnsure(
        !str_contains($phpBuildBlock, $privateConfigureArgument),
        'PHP configure command exposes the private dependency prefix: ' . $privateConfigureArgument,
    );
}

foreach (['runtime_require_command readelf', 'runtime_require_command strings', 'xcrun --find otool', 'xcrun --find strings'] as $required) {
    unixBuildEnsure(str_contains($preflight, $required), 'Unix preflight is missing inspection tool: ' . $required);
}

echo "Unix native build contract tests passed.\n";
