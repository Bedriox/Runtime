# Releasing Runtime

A Runtime release promotes artifacts that already passed native qualification;
it does not rebuild them.

Build scripts deliberately refuse to replace an existing target archive or
checksum. Review and move an already qualified artifact before rebuilding the
same target; do not delete an artifact merely to bypass this guard.

## Release gate

- Review every changed source revision, hash, license, advisory, and compiler.
- Run repository validation and the complete native target suite.
- Verify every archive entry and hash, then extract the archive to a clean path
  containing spaces and execute its relocated PHP.
- Run Bedriox integration for each supported target.
- Confirm that experimental targets are clearly labelled.
- Generate SHA-256 checksums and the runtime manifest.
- Include all required licenses and third-party acknowledgements.
- Confirm that archives contain no source trees, caches, logs, credentials,
  private paths, environment dumps, or signing keys.
- Keep each `.sha256` checksum adjacent to its archive; never place it inside
  the archived `bin/` tree.

Hosted release workflows use minimal permissions and pinned actions. Expensive
multi-platform builds are manual or release-oriented, use concurrency
cancellation, and must remain within an explicitly approved runner budget.

## Dependency updates

A dependency update is a focused reviewed change. Update its immutable source,
digest, license metadata, notices, and compatibility fixtures together. Re-run
every target affected by the ABI or native library change. A PHP patch update
requires the complete matrix, not only repository validation.

## Rollback

Retain the preceding qualified archive and checksum. Bedriox can restore its
previous `bin/` directory and startup scripts without modifying `C:\PHP`,
system `PATH`, registries, firewall policy, or certificate stores.
