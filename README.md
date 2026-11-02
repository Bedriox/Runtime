# Bedriox Runtime

Bedriox Runtime builds the consistent PHP environment used to run
[Bedriox](https://github.com/Bedriox/Bedriox), a Minecraft: Bedrock Edition
server written in PHP. It packages the interpreter, native libraries,
extensions, and certificate trust needed for the server to behave consistently
across supported systems.

The project is currently preparing its first qualified artifacts. Do not treat
a target as supported until a corresponding release archive has passed the
published qualification suite.

## Goals

- Reproducible, hash-verified PHP 8.4 builds
- Native Bedrock LevelDB compatibility
- Ready-to-use TLS and public CA configuration
- SQLite, ZIP, GMP, sockets, Sodium, mbstring, Phar, OPcache, and WebRTC data-channel support
- Relocatable packages that install directly into Bedriox's `bin/` directory
- Native qualification on Windows, Linux, and macOS

## Intended targets

| Platform | Architecture | Initial status |
| --- | --- | --- |
| Windows | x86-64 | First qualification target |
| Linux | x86-64 | Planned |
| Linux | ARM64 | Planned |
| macOS | ARM64 | Planned |
| macOS | x86-64 | Planned |
| Windows | ARM64 | Experimental until the complete native gate passes |

## Repository layout

```text
config/       Packaged PHP configuration templates
docs/         Architecture, build, test, and release guides
fixtures/     Independent compatibility fixtures
licenses/     Redistributed dependency licenses
manifests/    Immutable source, hash, license, and target metadata
scripts/      Public build entry points and platform build modules
tests/        Runtime and package qualification tests
tools/        Manifest, download, package, and validation utilities
```

Build, cache, download, and artifact directories are deliberately ignored.

## Building

The stable public entry points will be:

```powershell
.\compile.ps1 -Target windows-x86_64
```

```cmd
compile.bat -Target windows-x86_64
```

```shell
./compile.sh --target linux-x86_64
```

Exact prerequisites and currently implemented targets are documented in
[`docs/building.md`](docs/building.md). The scripts must refuse unsupported
targets or incomplete toolchains instead of producing a partial package.

## Validation

Repository metadata and scripts are checked with:

```shell
php tools/validate.php
```

A compiled artifact must additionally pass the complete native qualification
suite documented in [`docs/testing.md`](docs/testing.md).

Both platform build paths use the same finalizer. It stages the relocatable
configuration and exact target licenses, creates a deterministic byte/hash
inventory, and runs qualification with the packaged PHP executable. An archive
and its SHA-256 sidecar are emitted only after that gate succeeds.

## Installation shape

A release archive expands into the server's existing `bin/` directory:

```text
bin/
|-- php.exe or php
|-- php.ini
|-- ext/
|-- lib/
|-- lib/
|-- certs/cacert.pem
|-- config/openssl.cnf
|-- licenses/
`-- runtime-manifest.json
```

Bedriox startup scripts use this runtime explicitly. Runtime does not replace a
system PHP installation or modify the system certificate store.

Project information and releases are available at [bedriox.com](https://bedriox.com).

## Third-party foundation

Bedriox Runtime uses the Bedrock-compatible
[`pmmp/leveldb`](https://github.com/pmmp/leveldb) fork and
[`pmmp/php-leveldb`](https://github.com/pmmp/php-leveldb) extension. These
projects add the raw-zlib and zstd LevelDB compatibility needed for Minecraft:
Bedrock Edition worlds. Their licenses and required notices are retained in
distributed packages. Bedriox is an independent project and is not affiliated
with PMMP.

LevelDB compatibility is qualified with independent databases produced from
pinned df-mc/goleveldb and Google LevelDB commits. The fixture sources,
generator inputs, licenses, archive hashes, and reproducible build commands are
recorded in `fixtures/metadata.json`. These fixtures contain only the Runtime
test corpus and no Minecraft assets or user world data.

Minecraft, Bedrock Edition, Mojang Studios, and Microsoft are trademarks of
their respective owners. Bedriox is independent and is not affiliated with or
endorsed by Mojang Studios or Microsoft.

## License

Bedriox-authored source in this repository is licensed under GPL-3.0-only.
Bundled dependencies and certificate data retain their respective licenses;
see `THIRD_PARTY_NOTICES.md` and the packaged `licenses/` directory.
