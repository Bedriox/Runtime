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
$downloader = file_get_contents($root . '/scripts/unix/download-sources.sh');
$extractor = file_get_contents($root . '/scripts/unix/extract-sources.sh');
$preflight = file_get_contents($root . '/scripts/unix/preflight.sh');
$packager = file_get_contents($root . '/tools/package.php');
$workflow = file_get_contents($root . '/.github/workflows/native-build.yml');
unixBuildEnsure(
    is_string($compiler) && is_string($downloader) && is_string($extractor) && is_string($preflight) && is_string($packager) && is_string($workflow),
    'Unix build contract inputs could not be read.',
);
unixBuildEnsure(str_contains($workflow, "if: runner.os != 'macOS'\n        uses: shivammathur/setup-php@"), 'macOS still uses the failing generic PHP bootstrap action.');
unixBuildEnsure(str_contains($workflow, 'brew install php@8.4 autoconf automake bison cmake libtool pkg-config re2c'), 'macOS does not install its PHP 8.4 bootstrap with the native Homebrew toolchain.');
unixBuildEnsure(str_contains($workflow, 'echo "$(brew --prefix php@8.4)/bin" >> "$GITHUB_PATH"'), 'macOS does not expose the pinned PHP 8.4 bootstrap to later build steps.');
unixBuildEnsure(str_contains($workflow, 'PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4'), 'macOS does not verify its PHP 8.4 bootstrap before compilation.');

foreach ([
    'runtime_prefix=/opt/bedriox-runtime',
    'make install INSTALL_ROOT="$install_stage"',
    'make_install_autoconf libiconv',
    'autoreconf -fi',
    '--disable-shared --enable-static --enable-cxx --with-pic $gmp_platform_flags',
    "linux-x86_64) gmp_platform_flags='--build=x86_64-linux-gnu --enable-fat'",
    "macos-x86_64) gmp_platform_flags='--build=x86_64-apple-darwin --enable-fat'",
    'make -j"$jobs" check',
    'installed GMP failed its static consumer probe',
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
    'export CPPFLAGS="-I$prefix/include"',
    'export LDFLAGS="-L$prefix/lib"',
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
    'GMP_CFLAGS="$GMP_CFLAGS" GMP_LIBS="$GMP_LIBS"',
    '--with-iconv="$php_dependency_root"',
    'grep -F "$private_root" main/build-defs.h',
    'PHP build definitions contain a private $private_root_kind path',
    'sanitized PHP build-definition witness:',
    'sanitized PHP configure diagnostic:',
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
    'libdatachannel_source=$(source_path libdatachannel)',
    'rm -rf -- "$libdatachannel_work/deps/libjuice" "$libdatachannel_work/deps/plog" "$libdatachannel_work/deps/usrsctp"',
    'cp -R -p -- "$(source_path libjuice)" "$libdatachannel_work/deps/libjuice"',
    'cp -R -p -- "$(source_path plog)" "$libdatachannel_work/deps/plog"',
    'cp -R -p -- "$(source_path usrsctp)" "$libdatachannel_work/deps/usrsctp"',
    '-DNO_EXAMPLES=ON',
    '-DNO_TESTS=ON',
    '-DNO_MEDIA=ON',
    '-DNO_WEBSOCKET=ON',
    '[ -f "$prefix/lib/libssl.a" ] || runtime_die "pinned OpenSSL SSL archive is missing"',
    '[ -f "$prefix/lib/libcrypto.a" ] || runtime_die "pinned OpenSSL crypto archive is missing"',
    '-DOPENSSL_USE_STATIC_LIBS=TRUE',
    '-DOPENSSL_SSL_LIBRARY=$prefix/lib/libssl.a',
    '-DOPENSSL_CRYPTO_LIBRARY=$prefix/lib/libcrypto.a',
    '-DOPENSSL_INCLUDE_DIR=$prefix/include',
    "grep -F 'PHP_ADD_LIBRARY_WITH_PATH([datachannel], [\$PHP_LIBDATACHANNEL/lib], [WEBRTC_SHARED_LIBADD])'",
    "PHP_ADD_LIBRARY([datachannel], [1], [WEBRTC_SHARED_LIBADD])",
    'LDFLAGS="-L$prefix/lib"',
    './configure --with-php-config="$build_php_config" --enable-webrtc --with-libdatachannel="$prefix"',
    'cp -- "$extension_dir/webrtc.so" "$package_bin/ext/webrtc.so"',
    'libdatachannel runtime library is missing',
] as $required) {
    unixBuildEnsure(str_contains($compiler, $required), 'Unix native build is missing hardening contract: ' . $required);
}

foreach (['libdatachannel', 'libjuice', 'plog', 'usrsctp', 'ext-webrtc'] as $webRtcSource) {
    unixBuildEnsure(
        preg_match("/source_ids='[^']*\\b" . preg_quote($webRtcSource, '/') . "\\b[^']*'/", $downloader) === 1,
        'Unix source download closure omits WebRTC input: ' . $webRtcSource,
    );
    unixBuildEnsure(
        preg_match("/source_ids='[^']*\\b" . preg_quote($webRtcSource, '/') . "\\b[^']*'/", $extractor) === 1,
        'Unix source extraction closure omits WebRTC input: ' . $webRtcSource,
    );
}

foreach (['LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH'] as $libraryPathVariable) {
    unixBuildEnsure(
        str_contains($packager, "\$environment['" . $libraryPathVariable . "'] = \$runtimeRoot . DIRECTORY_SEPARATOR . 'lib';"),
        'Packaged runtime qualification does not expose its private library directory through ' . $libraryPathVariable . '.',
    );
}

$gmpBlockStart = strpos($compiler, 'gmp_source=$(source_path gmp)');
$gmpBlockEnd = strpos($compiler, 'make_install_autoconf libiconv');
unixBuildEnsure($gmpBlockStart !== false && $gmpBlockEnd !== false, 'Dedicated GMP build policy could not be isolated.');
$gmpBuildBlock = substr($compiler, $gmpBlockStart, $gmpBlockEnd - $gmpBlockStart);
unixBuildEnsure(str_contains($gmpBuildBlock, 'autoreconf -fi'), 'GMP does not refresh its stale bundled libtool rules.');
unixBuildEnsure(str_contains($gmpBuildBlock, 'make -j"$jobs" check'), 'GMP does not pass its upstream test suite before installation.');
unixBuildEnsure(str_contains($gmpBuildBlock, '"$prefix/lib/libgmp.a"'), 'GMP does not test its exact installed static archive.');

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
