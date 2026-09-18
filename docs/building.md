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

Windows ARM64 is experimental. The build must refuse to label it supported
until PHP, GMP, LevelDB, the PHP extension, and the complete suite execute on a
native ARM64 system.

## Linux

Use a current supported distribution with a C/C++ compiler, GNU make, CMake,
autoconf, automake, libtool, bison, re2c, pkg-config, and archive tools.

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
inside the archive.
