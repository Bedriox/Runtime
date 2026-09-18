#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
# shellcheck source=common.sh
. "$script_dir/common.sh"

[ "$#" -eq 5 ] || runtime_die "native compilation requires repository root, target, source root, download root, and build root"
repository_root=$1
target=$2
source_root=$3
download_root=$4
build_root=$5
prefix="$build_root/prefix"
runtime_prefix=/opt/bedriox-runtime
install_stage="$build_root/install-stage"
install_root="$install_stage$runtime_prefix"
package_bin="$install_root/bin"
mkdir -p -- "$prefix" "$package_bin"

runtime_cflags="-O2 -g0 -fPIC -ffile-prefix-map=$build_root=. -fdebug-prefix-map=$build_root=. -fmacro-prefix-map=$build_root=. -ffile-prefix-map=$source_root=./sources -fdebug-prefix-map=$source_root=./sources -fmacro-prefix-map=$source_root=./sources -ffile-prefix-map=$repository_root=. -fdebug-prefix-map=$repository_root=. -fmacro-prefix-map=$repository_root=."

jobs=${BEDRIOX_RUNTIME_JOBS:-}
if [ -z "$jobs" ]; then
	case "$(uname -s)" in
		Darwin) jobs=$(sysctl -n hw.logicalcpu) ;;
		*) jobs=$(getconf _NPROCESSORS_ONLN) ;;
	esac
fi
case "$jobs" in *[!0-9]*|'') runtime_die "BEDRIOX_RUNTIME_JOBS must be a positive integer" ;; esac
[ "$jobs" -gt 0 ] || runtime_die "BEDRIOX_RUNTIME_JOBS must be positive"

source_path() {
	id=$1
	digest=$(php "$repository_root/tools/manifest.php" source "$id" sha256)
	path="$source_root/$id-$digest"
	[ -d "$path" ] || runtime_die "extracted source is missing: $id"
	printf '%s\n' "$path"
}

make_install_autoconf() {
	id=$1
	shift
	source=$(source_path "$id")
	work="$build_root/build-$id"
	cp -R -p -- "$source" "$work"
	(
		cd "$work"
		[ -x ./configure ] || autoreconf -fi
		CFLAGS="$runtime_cflags" CXXFLAGS="$runtime_cflags" ./configure --prefix="$prefix" --disable-shared --enable-static "$@"
		make -j"$jobs"
		make install
	)
}

make_install_cmake() {
	id=$1
	source_subdirectory=$2
	shift 2
	source="$(source_path "$id")/$source_subdirectory"
	[ -f "$source/CMakeLists.txt" ] || runtime_die "CMake source is missing for $id: $source_subdirectory"
	work="$build_root/build-$id"
	cmake -S "$source" -B "$work" \
		-DCMAKE_BUILD_TYPE=Release \
		-DCMAKE_INSTALL_PREFIX="$prefix" \
		-DCMAKE_INSTALL_LIBDIR=lib \
		-DCMAKE_PREFIX_PATH="$prefix" \
		-DCMAKE_POSITION_INDEPENDENT_CODE=ON \
		-DCMAKE_C_FLAGS="$runtime_cflags" \
		-DCMAKE_CXX_FLAGS="$runtime_cflags" \
		-DBUILD_SHARED_LIBS=OFF "$@"
	cmake --build "$work" --parallel "$jobs"
	cmake --install "$work"
}

zlib_source=$(source_path zlib)
zlib_work="$build_root/build-zlib"
cp -R -p -- "$zlib_source" "$zlib_work"
(
	cd "$zlib_work"
	CFLAGS="$runtime_cflags" ./configure --prefix="$prefix" --static
	make -j"$jobs"
	make install
)
gmp_source=$(source_path gmp)
gmp_work="$build_root/build-gmp"
cp -R -p -- "$gmp_source" "$gmp_work"
case "$target" in
	linux-x86_64) gmp_platform_flags='--build=x86_64-linux-gnu --enable-fat' ;;
	linux-arm64) gmp_platform_flags='--build=aarch64-linux-gnu' ;;
	macos-x86_64) gmp_platform_flags='--build=x86_64-apple-darwin --enable-fat' ;;
	macos-arm64) gmp_platform_flags='--build=aarch64-apple-darwin' ;;
esac
(
	cd "$gmp_work"
	# GMP 6.3.0 bundles libtool 2.4.6, whose flat-namespace rules are broken
	# on current Intel macOS linkers. Regenerate with the required host tools.
	autoreconf -fi
	CFLAGS="$runtime_cflags" CXXFLAGS="$runtime_cflags" ./configure \
		--prefix="$prefix" --disable-shared --enable-static --enable-cxx --with-pic $gmp_platform_flags
	make -j"$jobs"
	make -j"$jobs" check
	make install
)
gmp_probe_source="$build_root/gmp-link-probe.c"
gmp_probe_binary="$build_root/gmp-link-probe"
printf '%s\n' \
	'#include <gmp.h>' \
	'#include <stdlib.h>' \
	'int main(void) {' \
	'    mpz_t root, remainder, value;' \
	'    mpz_inits(root, remainder, value, NULL);' \
	'    mpz_set_ui(value, 17);' \
	'    mpz_rootrem(root, remainder, value, 2);' \
	'    int valid = mpz_cmp_ui(root, 4) == 0 && mpz_cmp_ui(remainder, 1) == 0;' \
	'    mpz_clears(root, remainder, value, NULL);' \
	'    return valid ? 0 : 1;' \
	'}' > "$gmp_probe_source"
cc $runtime_cflags -I"$prefix/include" "$gmp_probe_source" "$prefix/lib/libgmp.a" -o "$gmp_probe_binary"
"$gmp_probe_binary" || runtime_die "installed GMP failed its static consumer probe"
make_install_autoconf libiconv

openssl_source=$(source_path openssl)
openssl_work="$build_root/build-openssl"
openssl_stage="$build_root/install-openssl"
openssl_cflags="-O2 -g0 -fPIC"
cp -R -p -- "$openssl_source" "$openssl_work"
case "$target" in
	linux-x86_64) openssl_target=linux-x86_64 ;;
	linux-arm64) openssl_target=linux-aarch64 ;;
	macos-x86_64) openssl_target=darwin64-x86_64-cc ;;
	macos-arm64) openssl_target=darwin64-arm64-cc ;;
esac
(
	cd "$openssl_work"
	# OpenSSL exposes its configured compiler flags through OPENSSL_VERSION(1).
	# Its in-tree build uses relative source paths, so private prefix-map operands
	# are unnecessary here and must not become runtime build information.
	CFLAGS="$openssl_cflags" ./Configure "$openssl_target" --prefix="$runtime_prefix" \
		--openssldir="$runtime_prefix/ssl" --libdir=lib no-shared no-tests
	make -j"$jobs"
	make install_sw DESTDIR="$openssl_stage"
)
openssl_install_root="$openssl_stage$runtime_prefix"
[ -d "$openssl_install_root/include/openssl" ] || runtime_die "staged OpenSSL headers are missing"
[ -f "$openssl_install_root/lib/libcrypto.a" ] || runtime_die "staged OpenSSL libcrypto is missing"
cp -R -p -- "$openssl_install_root/." "$prefix/"
for metadata in "$prefix"/lib/pkgconfig/libcrypto.pc "$prefix"/lib/pkgconfig/libssl.pc "$prefix"/lib/pkgconfig/openssl.pc; do
	[ -f "$metadata" ] || runtime_die "staged OpenSSL pkg-config metadata is missing: ${metadata##*/}"
	php -r '
$contents = file_get_contents($argv[1]);
if ($contents === false || !str_contains($contents, $argv[2])) {
    fwrite(STDERR, "OpenSSL metadata does not contain the neutral runtime prefix.\n");
    exit(1);
}
$rewritten = str_replace($argv[2], $argv[3], $contents, $count);
if ($count < 1 || file_put_contents($argv[1], $rewritten, LOCK_EX) === false) {
    fwrite(STDERR, "Could not relocate OpenSSL build metadata.\n");
    exit(1);
}
' "$metadata" "$runtime_prefix" "$prefix"
done

make_install_autoconf sqlite
(
	export CPPFLAGS="-I$prefix/include"
	export LDFLAGS="-L$prefix/lib"
	make_install_autoconf libxml2 --sysconfdir="$runtime_prefix/etc" --without-python --without-lzma
)
make_install_autoconf oniguruma
make_install_autoconf libsodium
make_install_cmake zstd build/cmake -DZSTD_BUILD_PROGRAMS=OFF -DZSTD_BUILD_TESTS=OFF -DZSTD_BUILD_SHARED=OFF
make_install_cmake libzip . \
	-DBUILD_TOOLS=OFF -DBUILD_REGRESS=OFF -DBUILD_EXAMPLES=OFF -DBUILD_DOC=OFF \
	-DENABLE_BZIP2=OFF -DENABLE_LZMA=OFF -DENABLE_ZSTD=ON \
	-DOPENSSL_ROOT_DIR="$prefix" -DCMAKE_DISABLE_FIND_PACKAGE_BZip2=TRUE -DCMAKE_DISABLE_FIND_PACKAGE_LibLZMA=TRUE
make_install_cmake leveldb . \
	-DLEVELDB_BUILD_TESTS=OFF -DLEVELDB_BUILD_BENCHMARKS=OFF -DLEVELDB_INSTALL=ON \
	-DLEVELDB_SNAPPY=OFF -DLEVELDB_TCMALLOC=OFF -DLEVELDB_ZLIB=ON -DLEVELDB_ZSTD=ON
grep -E '^ZLIB_LIBRARY[^=]*=.+' "$build_root/build-leveldb/CMakeCache.txt" | grep -qv 'NOTFOUND' || runtime_die "LevelDB did not resolve the pinned zlib build"
grep -Fx "zstd_LIBRARIES:FILEPATH=$prefix/lib/libzstd.a" "$build_root/build-leveldb/CMakeCache.txt" >/dev/null \
	|| runtime_die "LevelDB did not resolve the pinned zstd library"
grep -Fx "zstd_INCLUDE_DIRS:PATH=$prefix/include" "$build_root/build-leveldb/CMakeCache.txt" >/dev/null \
	|| runtime_die "LevelDB did not resolve the pinned zstd headers"

export PKG_CONFIG_PATH="$prefix/lib/pkgconfig:$prefix/share/pkgconfig"
export CPPFLAGS="-I$prefix/include"
export LDFLAGS="-L$prefix/lib"
export PATH="$prefix/bin:$PATH"
make_install_autoconf curl --with-openssl="$prefix" --with-zlib="$prefix" --with-zstd="$prefix" \
	--disable-ldap --disable-ldaps --without-brotli --without-libidn2 --without-libpsl --without-nghttp2

php_source=$(source_path php)
php_work="$build_root/build-php"
cp -R -p -- "$php_source" "$php_work"
(
	cd "$php_work"
	php_dependency_root=../prefix
	php_pkg_config_path="$php_dependency_root/lib/pkgconfig:$php_dependency_root/share/pkgconfig"
	# Override libzip's pkg-config result with its complete static link closure.
	# This keeps compression dependencies after libzip on one-pass Unix linkers.
	LIBZIP_CFLAGS=$(PKG_CONFIG_PATH="$php_pkg_config_path" pkg-config --define-variable=prefix="$php_dependency_root" --cflags libzip)
	LIBZIP_LIBS=$(PKG_CONFIG_PATH="$php_pkg_config_path" pkg-config --define-variable=prefix="$php_dependency_root" --static --libs libzip)
	# Static libcurl needs its complete private dependency closure. On macOS
	# this includes the platform frameworks required by curl_easy_perform().
	CURL_CFLAGS=$(PKG_CONFIG_PATH="$php_pkg_config_path" pkg-config \
		--define-variable=prefix="$php_dependency_root" --define-variable=exec_prefix="$php_dependency_root" \
		--define-variable=libdir="$php_dependency_root/lib" --define-variable=includedir="$php_dependency_root/include" \
		--cflags libcurl)
	CURL_LIBS=$(PKG_CONFIG_PATH="$php_pkg_config_path" pkg-config \
		--define-variable=prefix="$php_dependency_root" --define-variable=exec_prefix="$php_dependency_root" \
		--define-variable=libdir="$php_dependency_root/lib" --define-variable=includedir="$php_dependency_root/include" \
		--static --libs libcurl)
	CURL_CFLAGS=$(php -n -r 'echo str_replace($argv[2], $argv[3], $argv[1]);' -- "$CURL_CFLAGS" "$prefix" "$php_dependency_root")
	CURL_LIBS=$(php -n -r 'echo str_replace($argv[2], $argv[3], $argv[1]);' -- "$CURL_LIBS" "$prefix" "$php_dependency_root")
	GMP_CFLAGS="-I$php_dependency_root/include"
	GMP_LIBS="-L$php_dependency_root/lib -lgmp"
	case "$CURL_CFLAGS $CURL_LIBS" in
		*"$prefix"*) runtime_die "relocated libcurl flags retain the private dependency prefix" ;;
	esac
	php_platform_ldflags=
	case "$target" in
		macos-*)
			for framework in CoreFoundation CoreServices SystemConfiguration; do
				case " $CURL_LIBS " in
					*" -framework $framework "*) ;;
					*) runtime_die "pinned macOS libcurl metadata omits framework: $framework" ;;
				esac
			done
			php_platform_ldflags='-framework CoreFoundation -framework CoreServices -framework SystemConfiguration'
			;;
	esac
	if ! CFLAGS="$runtime_cflags" CXXFLAGS="$runtime_cflags" \
		PKG_CONFIG_PATH="$php_pkg_config_path" CPPFLAGS="-I$php_dependency_root/include" LDFLAGS="-L$php_dependency_root/lib $php_platform_ldflags" \
		LIBZIP_CFLAGS="$LIBZIP_CFLAGS" LIBZIP_LIBS="$LIBZIP_LIBS" CURL_CFLAGS="$CURL_CFLAGS" CURL_LIBS="$CURL_LIBS" \
		GMP_CFLAGS="$GMP_CFLAGS" GMP_LIBS="$GMP_LIBS" \
		./configure --prefix="$runtime_prefix" --disable-rpath \
		--with-config-file-path=. \
		--disable-all --disable-cgi --disable-phpdbg --enable-cli --enable-phar --enable-filter \
		--enable-ctype --enable-dom --enable-fileinfo --enable-mbstring --enable-opcache \
		--enable-pcntl --enable-pdo --enable-posix --enable-session --enable-simplexml \
		--enable-sockets --enable-tokenizer --enable-xml --enable-xmlreader --enable-xmlwriter \
		--with-openssl --with-curl --with-zlib \
		--with-zip --with-gmp --with-sodium \
		--with-iconv="$php_dependency_root" --with-sqlite3 --with-pdo-sqlite --with-libxml; then
		php -n -r '
$lines = file($argv[1], FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "PHP configure failed; config.log could not be read.\n");
    exit;
}
$match = null;
foreach ($lines as $index => $line) {
    if (str_contains($line, "__gmpz_rootrem")) {
        $match = $index;
    }
}
$start = $match === null ? max(0, count($lines) - 30) : max(0, $match - 12);
$end = $match === null ? count($lines) : min(count($lines), $match + 13);
$roots = array_slice($argv, 2);
fwrite(STDERR, "sanitized PHP configure diagnostic:\n");
for ($index = $start; $index < $end; ++$index) {
    $line = $lines[$index];
    foreach ($roots as $rootIndex => $root) {
        $line = str_replace($root, "<private-" . ["build", "source", "repository", "download"][$rootIndex] . "-root>", $line);
    }
    fwrite(STDERR, substr($line, 0, 1024) . "\n");
}
' config.log "$build_root" "$source_root" "$repository_root" "$download_root"
		runtime_die "PHP configuration failed"
	fi
	for private_root_kind in build source repository download; do
		case "$private_root_kind" in
			build) private_root=$build_root ;;
			source) private_root=$source_root ;;
			repository) private_root=$repository_root ;;
			download) private_root=$download_root ;;
		esac
		if grep -F "$private_root" main/build-defs.h >/dev/null; then
			php -n -r '
$lines = file($argv[1], FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "error: could not read PHP build definitions\n");
    exit(1);
}
$needle = $argv[2];
$roots = array_slice($argv, 3);
foreach ($lines as $line) {
    if (!str_contains($line, $needle)) {
        continue;
    }
    foreach ($roots as $index => $root) {
        $line = str_replace($root, "<private-" . ["build", "source", "repository", "download"][$index] . "-root>", $line);
    }
    fwrite(STDERR, "sanitized PHP build-definition witness: " . substr($line, 0, 1024) . "\n");
    break;
}
' main/build-defs.h "$private_root" "$build_root" "$source_root" "$repository_root" "$download_root"
			runtime_die "PHP build definitions contain a private $private_root_kind path"
		fi
	done
	extra_libs=$(sed -n 's/^EXTRA_LIBS = //p' Makefile)
	case "${extra_libs##*-lzip}" in
		*'-lzstd'*) ;;
		*) runtime_die "PHP link plan does not retain zstd after static libzip" ;;
	esac
	make -j"$jobs"
	make install INSTALL_ROOT="$install_stage"
)

leveldb_extension_source=$(source_path php-leveldb)
leveldb_extension_work="$build_root/build-php-leveldb"
cp -R -p -- "$leveldb_extension_source" "$leveldb_extension_work"
build_phpize="$build_root/phpize-build"
build_php_config="$build_root/php-config-build"
for tool in phpize php-config; do
	input="$package_bin/$tool"
	output="$build_root/$tool-build"
	[ -f "$input" ] || runtime_die "PHP install is missing build tool: $tool"
	"$package_bin/php" -n -r '
$input = file_get_contents($argv[3]);
if ($input === false || !str_contains($input, $argv[1])) {
    fwrite(STDERR, "Build tool does not contain the neutral runtime prefix.\n");
    exit(1);
}
$output = str_replace($argv[1], $argv[2], $input, $count);
if ($count < 1 || file_put_contents($argv[4], $output, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write relocated build tool.\n");
    exit(1);
}
' "$runtime_prefix" "$install_root" "$input" "$output"
	chmod 0755 "$output"
done
[ -f "$install_root/include/php/main/php.h" ] || runtime_die "staged PHP development headers are missing"
[ "$("$build_php_config" --prefix)" = "$install_root" ] || runtime_die "relocated php-config reports the wrong build prefix"
"$build_phpize" --version >/dev/null || runtime_die "relocated phpize is not executable"
(
	cd "$leveldb_extension_work"
	"$build_phpize"
	case "$target" in
		linux-*) leveldb_dependencies='-lzstd -lz -lstdc++'; leveldb_cxx_runtime='-lstdc++' ;;
		macos-*) leveldb_dependencies='-lzstd -lz -lc++'; leveldb_cxx_runtime='-lc++' ;;
	esac
	for archive in "$prefix/lib/libleveldb.a" "$prefix/lib/libzstd.a" "$prefix/lib/libz.a"; do
		[ -f "$archive" ] || runtime_die "PHP LevelDB static dependency is missing: ${archive##*/}"
	done
	for dynamic_leveldb in "$prefix/lib/libleveldb.so" "$prefix/lib"/libleveldb.so.* "$prefix/lib/libleveldb.dylib"; do
		[ ! -e "$dynamic_leveldb" ] || runtime_die "PHP LevelDB dependency prefix contains a shared LevelDB library"
	done
	leveldb_link_closure="$prefix/lib/libleveldb.a $prefix/lib/libzstd.a $prefix/lib/libz.a $leveldb_cxx_runtime"
	CFLAGS="$runtime_cflags" CXXFLAGS="$runtime_cflags" LEVELDB_SHARED_LIBADD="$leveldb_dependencies" \
		./configure --with-php-config="$build_php_config" --with-leveldb="$prefix"
	leveldb_shared_libadd=$(sed -n 's/^LEVELDB_SHARED_LIBADD = //p' Makefile)
	case "${leveldb_shared_libadd##*-lleveldb}" in
		*'-lzstd'*'-lz'*) ;;
		*) runtime_die "PHP LevelDB link plan does not retain its static compression dependencies" ;;
	esac
	case "${leveldb_shared_libadd##*-lleveldb}" in
		*'-lstdc++'*|*'-lc++'*) ;;
		*) runtime_die "PHP LevelDB link plan does not retain its C++ runtime dependency" ;;
	esac
	make -j"$jobs" LEVELDB_SHARED_LIBADD="$leveldb_link_closure"
	make install LEVELDB_SHARED_LIBADD="$leveldb_link_closure"
)

ca_url=$(php "$repository_root/tools/manifest.php" source ca-bundle url)
ca_file=${ca_url%%\?*}
ca_file=${ca_file##*/}
ca_path="$download_root/ca-bundle-$ca_file"
[ -f "$ca_path" ] || runtime_die "verified CA bundle is missing"

extension_dir=$($build_php_config --extension-dir)
mkdir -p -- "$package_bin/ext"
cp -- "$extension_dir/leveldb.so" "$package_bin/ext/leveldb.so"
if [ -f "$extension_dir/opcache.so" ]; then
	cp -- "$extension_dir/opcache.so" "$package_bin/ext/opcache.so"
fi

runtime_stage="$build_root/runtime-stage"
mkdir -p -- "$runtime_stage/ext"
cp -- "$package_bin/php" "$runtime_stage/php"
chmod 0755 "$runtime_stage/php"
for extension in "$package_bin"/ext/*; do
	[ -f "$extension" ] || runtime_die "runtime extension stage is empty"
	cp -- "$extension" "$runtime_stage/ext/${extension##*/}"
done

for binary in "$runtime_stage/php" "$runtime_stage"/ext/*; do
	binary_name=${binary##*/}
	dynamic_report="$build_root/dynamic-$binary_name.txt"
	strings_report="$build_root/strings-$binary_name.txt"
	case "$target" in
		linux-*)
			readelf -d "$binary" > "$dynamic_report" || runtime_die "could not inspect runtime ELF metadata: $binary_name"
			if grep -E '(RPATH|RUNPATH)' "$dynamic_report" >/dev/null; then
				runtime_die "runtime binary contains an RPATH or RUNPATH: $binary_name"
			fi
			if [ "$binary_name" = leveldb.so ] && grep -E 'NEEDED.*\[(libleveldb|libzstd|libz)\.so' "$dynamic_report" >/dev/null; then
				runtime_die "LevelDB extension retains a dynamic storage dependency"
			fi
			strings "$binary" > "$strings_report" || runtime_die "could not inspect runtime binary strings: $binary_name"
			;;
		macos-*)
			xcrun otool -l "$binary" > "$dynamic_report" || runtime_die "could not inspect runtime Mach-O metadata: $binary_name"
			if grep -q 'LC_RPATH' "$dynamic_report"; then
				runtime_die "runtime binary contains an LC_RPATH: $binary_name"
			fi
			if [ "$binary_name" = leveldb.so ] && xcrun otool -L "$binary" | grep -E '(libleveldb|libzstd|/libz\.)' >/dev/null; then
				runtime_die "LevelDB extension retains a dynamic storage dependency"
			fi
			xcrun strings "$binary" > "$strings_report" || runtime_die "could not inspect runtime binary strings: $binary_name"
			;;
	esac
	for private_root_kind in build source repository download; do
		case "$private_root_kind" in
			build) private_root=$build_root ;;
			source) private_root=$source_root ;;
			repository) private_root=$repository_root ;;
			download) private_root=$download_root ;;
		esac
		if grep -F "$private_root" "$strings_report" >/dev/null; then
			"$package_bin/php" -n -r '
$contents = file($argv[1], FILE_IGNORE_NEW_LINES);
if ($contents === false) {
    fwrite(STDERR, "error: could not read the binary strings report\n");
    exit(1);
}
$needle = $argv[2];
$roots = array_slice($argv, 3);
foreach ($contents as $line) {
    if (!str_contains($line, $needle)) {
        continue;
    }
    foreach ($roots as $index => $root) {
        $line = str_replace($root, "<private-" . ["build", "source", "repository", "download"][$index] . "-root>", $line);
    }
    fwrite(STDERR, "sanitized private path witness: " . substr($line, 0, 512) . "\n");
    break;
}
' "$strings_report" "$private_root" "$build_root" "$source_root" "$repository_root" "$download_root"
			runtime_die "runtime binary contains a private $private_root_kind path: $binary_name"
		fi
	done
done

compiler_identity=$(cc --version 2>/dev/null | sed -n '1p')
linker_identity=$(ld -v 2>&1 | sed -n '1p')
artifact_root=${BEDRIOX_RUNTIME_ARTIFACT_DIR:-"$repository_root/artifacts"}
runtime_assert_safe_root "artifact" "$artifact_root"
[ "$artifact_root" != "$repository_root" ] || runtime_die "artifact root must not be the repository root"
mkdir -p -- "$artifact_root"
package_parent="$build_root/package"
mkdir -p -- "$package_parent"
package_root="$package_parent/bin"
source_ids='php,leveldb,php-leveldb,ca-bundle,libsodium,libxml2,oniguruma,zlib,gmp,libiconv,curl,openssl,libzip,sqlite,zstd'
"$package_bin/php" -n "$repository_root/tools/package.php" \
	--repository-root="$repository_root" \
	--target="$target" \
	--invocation-root="$build_root" \
	--source-root="$runtime_stage" \
	--package-parent="$package_parent" \
	--package-root="$package_root" \
	--artifact-root="$artifact_root" \
	--ca-file="$ca_path" \
	--compiler="$compiler_identity" \
	--linker="$linker_identity" \
	--source-ids="$source_ids" \
	--format=tar.gz
