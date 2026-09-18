# Changelog

## Unreleased

- Sanitized Unix native-build path diagnostics now identify the embedded-path producer without exposing private runner directories.
- PHP is configured through neutral relative dependency paths, preventing private build locations from entering its compiled build metadata.
- The Unix LevelDB extension removes its configure-generated private RUNPATH while retaining the complete static dependency link closure.
- Windows native builds avoid PowerShell's reserved pipeline-input variable when handling verified archives and streams.

- Established the Runtime build, dependency, packaging, qualification, and
  release foundation for PHP 8.4.
- Hardened Windows packaging with a neutral compiled PHP prefix, an
  import-closed runtime allowlist, and bounded private/debug path inspection.
- Hardened TLS qualification to exercise cURL and OpenSSL through the packaged
  default CA configuration and distinguish verification rejection from network
  reachability failures.
- Preserved static libzip Zstandard support by retaining its compression
  libraries at the end of the Unix PHP link plan.
- Added the manual Windows x86-64 native build and qualification path on the
  hosted Windows 2022 runner, with a fail-closed PHP SDK task boundary.
- Materialized safe in-tree runtime file links as regular package files while
  continuing to reject directory, broken, and escaping links.
- Retained LevelDB's static compression and C++ runtime closure when linking
  the Unix PHP extension.
- Linked the Windows PHP extension through a packaged shared LevelDB library
  with pinned static compression dependencies and verified PE imports.
- Removed Unix runtime search paths, debug/build path leakage, and build-only
  PHP development launchers from the relocatable package contract.
- Neutralized OpenSSL's compiled configuration, engine, and provider-module
  directories while keeping build-only discovery inside the isolated stage.
- Kept the private Unix dependency prefix out of PHP's recorded configure
  command while retaining isolated compiler and pkg-config discovery.
- Added independently generated LevelDB fixtures for uncompressed,
  raw-DEFLATE, and zstd databases, with deterministic provenance, strict SST
  inspection, and packaged-runtime qualification before and after relocation.
- Kept OpenSSL build metadata and libxml2's compiled catalog location free of
  private invocation paths while preserving strict final binary inspection.
