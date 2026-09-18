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
shared-module discovery, sorted file inventory, and exclusion of manifest and
checksum sidecars from that inventory.

## Native package qualification

Every target artifact must execute tests for:

1. PHP version, architecture, thread-safety choice, and extension ABI.
2. The exact required extension set.
3. Signed PHAR execution.
4. SQLite creation, transactions, close, and reopen.
5. ZIP creation and extraction.
6. GMP arithmetic.
7. UDP loopback sockets.
8. OpenSSL and cURL with the bundled CA file.
9. Successful validation of a trusted certificate and rejection of an invalid
   or self-signed certificate.
10. LevelDB CRUD, batches, close/reopen, locking, and independent uncompressed,
    raw-zlib, and zstd fixtures.
11. Relocation of the complete package to a different absolute directory.
12. Exact package contents, licenses, manifest, and checksums.

The runtime manifest records every source in the target's exact build closure,
including its immutable version or commit, SHA-256, license identifier, and
packaged `licenseFile`. Its sorted `files` map records the byte count and
SHA-256 of every packaged file except the manifest itself and checksum
sidecars.

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
