# Building Runtime

Runtime is built from immutable, hash-verified sources. Build from a short local
path on a volume with enough space for PHP and its native dependencies.

## Common requirements

- Git
- a hash-verifying HTTPS download capability
- PHP 8.4 for repository validation
- CMake and the platform's native build tools
- sufficient free disk space for isolated source, build, and staging trees

Run repository validation before a native build:

```shell
php tools/validate.php
```

## Windows x86-64

PHP 8.4's supported Windows compiler family is Visual Studio 2022, also called
VS17, using its default v143 platform toolset. Install Visual Studio 2022
Community or Build Tools with:

- Desktop development with C++
- MSVC v143 x64/x86 build tools
- C++ ATL for the v143 build tools
- a v143-supported Windows SDK
- C++ CMake tools for Windows

Having the Visual Studio IDE alone is insufficient. Confirm the compiler from a
VS 2022 developer shell before building.

```powershell
.\compile.ps1 -Target windows-x86_64
```

`compile.bat` forwards to the same PowerShell implementation:

```cmd
compile.bat -Target windows-x86_64
```

The manual native-build workflow also supports `windows-x86_64` on the hosted
`windows-2022` runner. It uses the workflow's PHP 8.4 installation only as the
bootstrap interpreter, caches the exact repository `downloads` directory, and
then compiles and qualifies the package through the same entry point. The PHP
SDK task receives build paths through reserved process-scoped variables because
its `-t` task boundary does not forward positional arguments. Those variables
are rejected if already present and cleared immediately after the task exits.

The Windows build links the shared `leveldb.dll` to the exact pinned static
zlib and zstd archives from the PHP SDK dependency closure. It verifies CMake's
resolved headers and libraries, installs the LevelDB DLL beside `php.exe`, and
checks the final PE imports. `php_leveldb.dll` must import `leveldb.dll`, while
`leveldb.dll` must not depend on separate zlib or zstd DLLs.

PHP is configured with the neutral relative prefix and configuration path
`bedriox-runtime`; invocation-specific install paths are never compiled into
the executable. The build uses PHP's `nmake snap` target as a tooling output,
then creates a separate runtime stage from an allowlist. That stage contains
only `php.exe`, emitted `ext/*.dll` modules, and root DLLs reached by recursively
inspecting their PE imports. Every non-system import must resolve from the
snapshot or the reviewed LevelDB output; the staging step never searches the
ambient `PATH` for a missing library.

Before packaging, every staged PE is inspected with bounded ASCII and UTF-16
searches for the repository, invocation, user-profile, and temporary roots.
PE debug headers are also inspected and absolute PDB paths are rejected. Import
libraries, headers, PDBs, SDK tools, and other development outputs cannot pass
the runtime-layout allowlist.

Unix builds use a neutral libxml2 catalog location and neutral OpenSSL release
flags because both projects expose configure-time values as runtime strings.
Compiler prefix maps remain mandatory for sources that need them, but their
private operands are not passed into OpenSSL's public build-information string.
The finished executable and every extension are still scanned for all build,
source, download, and repository roots before packaging.

Windows ARM64 is experimental. The build must refuse to label it supported
until PHP, GMP, LevelDB, the PHP extension, and the complete suite execute on a
native ARM64 system. It is deliberately not selectable in the hosted workflow.

## Linux

Use a current supported distribution with a C/C++ compiler, GNU make, CMake,
autoconf, automake, libtool, bison, re2c, pkg-config, archive tools, and GNU
binutils (`readelf` and `strings`).

```shell
./compile.sh --target linux-x86_64
```

Linux ARM64 must be qualified on native ARM64 hardware or a native hosted
runner:

```shell
./compile.sh --target linux-arm64
```

## macOS

Install current Xcode command-line tools, CMake, autoconf, automake, libtool,
bison, re2c, and pkg-config. Build natively for the selected target:

```shell
./compile.sh --target macos-arm64
./compile.sh --target macos-x86_64
```

Cross-compilation alone cannot qualify a release.

The hosted native workflow installs the keg-only Homebrew `php@8.4` formula as
its bootstrap interpreter. It verifies the major and minor version before the
build starts instead of relying on the runner's newer default PHP or the
generic PHP setup action.

Unix builds compile PHP with a stable `/opt/bedriox-runtime` prefix and install
through an isolated staging root. Build-only `phpize` and `php-config` copies
are relocated inside that invocation and are not shipped. The release stage is
an allowlist containing only the PHP executable and required shared modules;
development launchers such as `phpize`, `php-config`, and `phar.phar` stay out
of the runtime package. PHP and the LevelDB module are built without debug path
records or runtime search paths. Linux `readelf` and macOS `otool`, followed by
binary string inspection, fail the build if an RPATH or private invocation,
source, download, or workspace path survives. OpenSSL uses the same neutral
prefix for its compiled configuration, engine, and provider-module directories;
only its build-time pkg-config metadata is relocated to the isolated dependency
stage. PHP dependency discovery uses the isolated compiler and pkg-config
environment without placing that private prefix in PHP's recorded configure
command.
The pinned static libcurl pkg-config closure is passed explicitly. PHP's
configure helpers ignore `-framework` pairs in that closure, so macOS builds
also verify the expected CoreFoundation, CoreServices, and SystemConfiguration
metadata and retain those frameworks in PHP's link checks.
GNU libiconv is built from the reviewed source manifest for every Unix target,
so PHP does not depend on SDK-only headers or an ambient Homebrew library.

## Output roots

The scripts use ignored repository-local directories for downloads, caches,
sources, build work, staging, and artifacts. Never point a cleanup option at a
workspace root, home directory, drive root, system PHP installation, or shared
dependency directory.

An unsupported target or incomplete toolchain must fail before downloading or
changing build state.

## Finalization contract

Native compilation writes into an isolated invocation directory. A shared
finalizer then creates `package/bin`, copies the pinned CA bundle and only the
licenses associated with that target's exact source closure, and renders
`php.ini` from `config/php.ini-production`. Launchers set
`BEDRIOX_RUNTIME_ROOT` to the absolute `bin` directory at runtime; the
configuration contains no build-machine paths.

The finalizer detects modules actually emitted into `bin/ext`. It writes load
directives only for those shared modules, so extensions compiled statically are
not loaded a second time. It runs `tests/qualify.php` with the newly packaged
PHP executable and configuration. It then builds a temporary archive, compares
every archived path, byte count, and SHA-256 to the qualified package, and
extracts it into a clean path containing spaces. The extracted PHP must execute
with its relocated configuration; unset or incorrect `BEDRIOX_RUNTIME_ROOT`
values fail closed. Unix tar packages also preserve an executable `bin/php`.
Only after these checks pass is the archive published and its adjacent
`.sha256` sidecar written. Checksum sidecars are never stored inside `bin` or
inside the archive. If another target supplies an allowed runtime file through
a safe in-tree symbolic link, finalization materializes it as a regular file;
broken links, directory links, and links outside that root are rejected.
