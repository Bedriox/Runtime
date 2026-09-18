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
install_root="$build_root/native"
package_bin="$install_root/bin"
mkdir -p -- "$prefix" "$package_bin"

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
		CFLAGS="-O2 -fPIC" CXXFLAGS="-O2 -fPIC" ./configure --prefix="$prefix" --disable-shared --enable-static "$@"
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
		-DBUILD_SHARED_LIBS=OFF "$@"
	cmake --build "$work" --parallel "$jobs"
	cmake --install "$work"
}

zlib_source=$(source_path zlib)
zlib_work="$build_root/build-zlib"
cp -R -p -- "$zlib_source" "$zlib_work"
(
	cd "$zlib_work"
	CFLAGS="-O2 -fPIC" ./configure --prefix="$prefix" --static
	make -j"$jobs"
	make install
)
make_install_autoconf gmp --enable-cxx

openssl_source=$(source_path openssl)
openssl_work="$build_root/build-openssl"
cp -R -p -- "$openssl_source" "$openssl_work"
case "$target" in
	linux-x86_64) openssl_target=linux-x86_64 ;;
	linux-arm64) openssl_target=linux-aarch64 ;;
	macos-x86_64) openssl_target=darwin64-x86_64-cc ;;
	macos-arm64) openssl_target=darwin64-arm64-cc ;;
esac
(
	cd "$openssl_work"
	./Configure "$openssl_target" --prefix="$prefix" --libdir=lib no-shared no-tests
	make -j"$jobs"
	make install_sw
)

make_install_autoconf sqlite
make_install_autoconf libxml2 --without-python --without-lzma
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
	# Override libzip's pkg-config result with its complete static link closure.
	# This keeps compression dependencies after libzip on one-pass Unix linkers.
	LIBZIP_CFLAGS=$(pkg-config --cflags libzip)
	LIBZIP_LIBS=$(pkg-config --static --libs libzip)
	LIBZIP_CFLAGS="$LIBZIP_CFLAGS" LIBZIP_LIBS="$LIBZIP_LIBS" ./configure --prefix="$install_root" \
		--with-config-file-path=. \
		--disable-all --disable-cgi --disable-phpdbg --enable-cli --enable-phar --enable-filter \
		--enable-ctype --enable-dom --enable-fileinfo --enable-mbstring --enable-opcache \
		--enable-pcntl --enable-pdo --enable-posix --enable-session --enable-simplexml \
		--enable-sockets --enable-tokenizer --enable-xml --enable-xmlreader --enable-xmlwriter \
		--with-openssl="$prefix" --with-curl="$prefix" --with-zlib="$prefix" \
		--with-zip --with-gmp="$prefix" --with-sodium="$prefix" \
		--with-iconv --with-sqlite3="$prefix" --with-pdo-sqlite="$prefix" --with-libxml
	extra_libs=$(sed -n 's/^EXTRA_LIBS = //p' Makefile)
	case "${extra_libs##*-lzip}" in
		*'-lzstd'*) ;;
		*) runtime_die "PHP link plan does not retain zstd after static libzip" ;;
	esac
	make -j"$jobs"
	make install
)

leveldb_extension_source=$(source_path php-leveldb)
leveldb_extension_work="$build_root/build-php-leveldb"
cp -R -p -- "$leveldb_extension_source" "$leveldb_extension_work"
(
	cd "$leveldb_extension_work"
	"$package_bin/phpize"
	case "$target" in
		linux-*) leveldb_dependencies='-lzstd -lz -lstdc++' ;;
		macos-*) leveldb_dependencies='-lzstd -lz -lc++' ;;
	esac
	LEVELDB_SHARED_LIBADD="$leveldb_dependencies" ./configure --with-php-config="$package_bin/php-config" --with-leveldb="$prefix"
	leveldb_shared_libadd=$(sed -n 's/^LEVELDB_SHARED_LIBADD = //p' Makefile)
	case "${leveldb_shared_libadd##*-lleveldb}" in
		*'-lzstd'*'-lz'*) ;;
		*) runtime_die "PHP LevelDB link plan does not retain its static compression dependencies" ;;
	esac
	case "${leveldb_shared_libadd##*-lleveldb}" in
		*'-lstdc++'*|*'-lc++'*) ;;
		*) runtime_die "PHP LevelDB link plan does not retain its C++ runtime dependency" ;;
	esac
	make -j"$jobs"
	make install
)

ca_url=$(php "$repository_root/tools/manifest.php" source ca-bundle url)
ca_file=${ca_url%%\?*}
ca_file=${ca_file##*/}
ca_path="$download_root/ca-bundle-$ca_file"
[ -f "$ca_path" ] || runtime_die "verified CA bundle is missing"

extension_dir=$($package_bin/php-config --extension-dir)
mkdir -p -- "$package_bin/ext"
cp -- "$extension_dir/leveldb.so" "$package_bin/ext/leveldb.so"
if [ -f "$extension_dir/opcache.so" ]; then
	cp -- "$extension_dir/opcache.so" "$package_bin/ext/opcache.so"
fi

compiler_identity=$(cc --version 2>/dev/null | sed -n '1p')
linker_identity=$(ld -v 2>&1 | sed -n '1p')
artifact_root=${BEDRIOX_RUNTIME_ARTIFACT_DIR:-"$repository_root/artifacts"}
runtime_assert_safe_root "artifact" "$artifact_root"
[ "$artifact_root" != "$repository_root" ] || runtime_die "artifact root must not be the repository root"
mkdir -p -- "$artifact_root"
package_parent="$build_root/package"
mkdir -p -- "$package_parent"
package_root="$package_parent/bin"
source_ids='php,leveldb,php-leveldb,ca-bundle,libsodium,libxml2,oniguruma,zlib,gmp,curl,openssl,libzip,sqlite,zstd'
"$package_bin/php" -n "$repository_root/tools/package.php" \
	--repository-root="$repository_root" \
	--target="$target" \
	--invocation-root="$build_root" \
	--source-root="$package_bin" \
	--package-parent="$package_parent" \
	--package-root="$package_root" \
	--artifact-root="$artifact_root" \
	--ca-file="$ca_path" \
	--compiler="$compiler_identity" \
	--linker="$linker_identity" \
	--source-ids="$source_ids" \
	--format=tar.gz
