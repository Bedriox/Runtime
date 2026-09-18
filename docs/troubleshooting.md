# Runtime Troubleshooting

## Visual Studio is installed but no compiler is found

The IDE and the C++ workload are separate installations. PHP 8.4 Windows builds
require the supported Visual Studio 2022/VS17 v143 compiler family. Add Desktop
development with C++, MSVC v143 x64/x86 tools, ATL, a compatible Windows SDK,
and CMake tools, then open a fresh VS 2022 developer shell.

## A download hash does not match

Stop. Remove only the named file from Runtime's verified download cache and try
again. Do not update the manifest to match an unexpected download. Confirm the
upstream release and digest through its authoritative channel before changing a
pin.

## TLS cannot find a certificate file

Confirm `certs/cacert.pem` exists inside the package and that both
`curl.cainfo` and `openssl.cafile` resolve it relative to the runtime. Do not
disable certificate verification or point a release package at a private
machine path.

Confirm the launcher sets `OPENSSL_CONF` to the runtime's
`config/openssl.cnf`. The file activates the built-in default provider and is
part of the qualified package inventory; pointing at a system-wide OpenSSL
configuration makes the runtime environment-dependent.

## LevelDB fails to load

Check that the PHP extension ABI matches the packaged PHP build and that all
native libraries are present under the package. A stock LevelDB build is not a
drop-in replacement for the required raw-zlib and zstd-compatible fork.
The Unix extension build supplies and verifies the complete static LevelDB
dependency closure through phpize's `LEVELDB_SHARED_LIBADD` input; do not move
compression or the platform C++ runtime before `libleveldb.a`.
On Windows, the PHP extension loads the packaged shared `leveldb.dll`. The
native build verifies that DLL was linked to the pinned static zlib and zstd
archives and places it beside `php.exe`; do not satisfy a missing dependency
through the system `PATH`.

## Unix PHP fails to link libzip Zstandard symbols

The Unix build deliberately supplies libzip's complete static `pkg-config`
closure through the supported `LIBZIP_LIBS` override and verifies that zstd
remains after libzip in PHP's generated link plan. Static archive order is
significant on one-pass Unix linkers: moving zstd before `libzip.a` leaves its
`ZSTD_*` references unresolved.

## Unix build reports a private path or runtime search path

Runtime binaries must not retain an invocation-specific RPATH, RUNPATH,
LC_RPATH, debug path, source path, download path, or repository path. Keep
`--disable-rpath`, the release prefix maps, the neutral staged prefix, and the
runtime-only file stage intact. Do not bypass the check by deleting the
inspection command or by allowing an ambient shared library. Inspect the
reported executable or module's dynamic metadata and strings to find the flag
or generated file that introduced the path.

## A package works only in its build directory

Treat this as a release-blocking relocation failure. Inspect dynamic-library
resolution, extension paths, CA paths, and generated configuration for absolute
build paths. Do not work around it by changing the system `PATH`.

## Windows staging reports an unresolved PE import

The Windows runtime stage is built from the actual import graph of `php.exe`
and the emitted extension DLLs. A dependency that is neither present in the
reviewed snapshot/LevelDB output nor on the explicit Windows system-DLL list is
refused. Add or correct the immutable dependency closure; do not copy a DLL
from the machine or add its directory to `PATH`.

## Windows staging reports a private path or PDB path

The release binaries retained an invocation, repository, profile, temporary,
or absolute debug-symbol path. Keep the neutral `bedriox-runtime` prefix and
fix the compiler/linker production flags. Do not delete the inspection or
rewrite bytes after linking.
