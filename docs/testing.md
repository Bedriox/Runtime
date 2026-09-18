# Runtime Testing

## Repository validation

Run from the repository root:

```shell
php tools/validate.php
```

This gate validates repository layout, dependency metadata, immutable pins,
SHA-256 values, license declarations, configuration, script syntax where the
host supports it, documentation links, and accidental build artifacts.

The repository gate also runs `tests/package-test.php`, which covers bounded
staging paths, rejection of ambient `php.ini`, target configuration rendering,
shared-module discovery, sorted file inventory, manifest exclusion, and
rejection of checksum sidecars inside the package. `tests/package-archive-test.php`
checks exact ZIP and tar entry/hash inventories, rejects undeclared entries,
extracts into paths containing spaces, and verifies the Unix executable mode.
`tests/windows-build-contract-test.php` locks the Windows x86-64 runner mapping,
the exact downloads cache, the split platform entry points, and the
environment-only PHP SDK task contract so positional argument forwarding cannot
silently regress. It also locks the neutral PHP prefix, `nmake snap` boundary,
PE-import closure, absence of native-library `PATH` fallback, runtime-only
Windows allowlist, and bounded private-path and debug/PDB inspection.
`tests/unix-build-contract-test.php` locks the neutral staged prefix, explicit
release flags, prefix maps, disabled runtime search paths, runtime-only file
allowlist, and native binary inspection tools used by Linux and macOS builds.

## Native package qualification

Every target artifact must execute tests for:

1. PHP version, architecture, thread-safety choice, and extension ABI.
2. The exact required extension set.
3. Signed PHAR execution.
4. SQLite creation, transactions, close, and reopen.
5. ZIP creation and extraction.
6. GMP arithmetic.
7. UDP loopback sockets.
8. OpenSSL and cURL through their bundled default-ini CA configuration; cURL
   qualification does not inject a per-request CA path.
9. Deterministic cURL and OpenSSL rejection of an ephemeral local self-signed
   certificate, plus real-network public-chain acceptance and certificate and
   hostname rejection by both clients. Negative network evidence must identify a
   verification failure; DNS, timeout, refusal, and routing failures do not
   qualify as successful rejection tests.
10. LevelDB CRUD, batches, close/reopen, locking, and independent uncompressed,
    raw-zlib, and zstd fixtures.
11. Clean extraction of the finished archive to a different absolute directory
    containing spaces, followed by execution with the relocated PHP and ini.
    Missing or incorrect `BEDRIOX_RUNTIME_ROOT` values must fail the relocation
    probe.
12. Exact package and archive contents, licenses, manifest, and checksums. The
    archive checksum is an adjacent sidecar and is not part of `bin` or the
    archive itself.
13. No RPATH, RUNPATH, LC_RPATH, debug mapping, or binary string exposes a
    private invocation, source, download, or repository path. Build-only PHP
    development launchers are absent from the runtime archive.

The runtime manifest records every source in the target's exact build closure,
including its immutable version or commit, SHA-256, license identifier, and
packaged `licenseFile`. Its sorted `files` map records the byte count and
SHA-256 of every packaged file except the manifest itself. A checksum sidecar
found inside the package is an error rather than an inventory exclusion.

## Bedriox integration

Before Runtime is installed into Bedriox, the artifact must pass its own native
suite. Integration then checks Bedriox CLI version output, startup, server-list
discovery, clean shutdown, and the full existing repository and workspace
regression gates. Runtime integration must not change protocol admission,
RakNet behavior, world output, gameplay defaults, or the client journey.

## Independent evidence

The LevelDB reader cannot be certified only by reading data emitted by the same
writer. Compatibility fixtures record their exact origin, format, checksum,
license, and review date. Test secrets, private world data, and proprietary game
assets are forbidden.
