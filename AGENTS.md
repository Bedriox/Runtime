# Repository Instructions

## Purpose and boundaries

This repository builds, qualifies, and packages the supported PHP runtime for
Bedriox. It owns build orchestration, immutable dependency manifests, native
library and extension compilation, package layout, runtime qualification,
checksums, and third-party notices.

It does not own Bedriox gameplay, world formats, Bedrock packets, RakNet, or
server configuration. Keep server integration in `Bedriox`, protocol behavior
in `Protocol`, registries in `Data`, and architectural decisions in reviewed
design proposals.

Do not add a source, extension, target, compiler, patch, or release capability
that is absent from the accepted Runtime architecture without first obtaining
an approved design change.

## Repository layout

- `manifests/` contains reviewed immutable source, hash, license, and target
  metadata.
- `scripts/` contains thin public build entry points and platform modules.
- `config/` contains package configuration templates.
- `tests/` contains executable runtime and package qualification tests.
- `fixtures/` contains independently sourced or generated compatibility
  fixtures with documented licenses and origins.
- `tools/` contains manifest, download, packaging, checksum, and validation
  utilities.
- `docs/` contains repository-specific build, architecture, testing, release,
  and troubleshooting guidance.
- `licenses/` contains distributable dependency license material.
- `.github/workflows/` contains validation, native target, and release gates.

Build output, downloaded archives, extracted source, caches, local credentials,
and machine-specific configuration never belong in Git.

## Build and source rules

- Target PHP 8.4 and pin an exact supported patch release and SHA-256 digest.
- Pin every external input to an immutable release or commit with an HTTPS URL,
  digest, license, and intended target set.
- Fail closed on hash mismatch, unknown manifest fields, missing licenses,
  unsupported targets, unsafe archives, or incompatible toolchains.
- Never silently use a library, compiler, certificate bundle, or extension from
  an ambient machine path.
- Keep downloads, sources, work, staging, and artifacts in distinct bounded
  roots. Resolve and validate every destructive cleanup target before removal.
- Do not weaken TLS to make a build pass. A public CA bundle must be pinned and
  its hash verified.
- Preserve upstream copyrights, licenses, acknowledgements, and naming rules in
  source and binary distributions.
- Treat PMMP build repositories as reviewed references. Do not copy scripts or
  implementation text without an explicit license and maintenance decision.
- Runtime packages must be relocatable. Configuration must not contain private
  absolute build or workspace paths.

## Target policy

The declared targets are Windows x86-64, Linux x86-64, Linux ARM64, macOS
x86-64, macOS ARM64, and Windows ARM64. A target is supported only after its
artifact executes natively and passes the complete qualification suite.

Windows x86-64 is the first release target. Windows ARM64 remains experimental
until upstream PHP, every dependency, and every qualification test pass on a
native Windows ARM64 runner. Do not describe an experimental artifact as
supported.

Use native hosted runners where practical. Cross-compilation does not replace
native artifact execution. Full native builds are manual or release-oriented;
do not add costly six-platform builds to every ordinary push.

## Qualification rules

A successful compile is not a qualified runtime. Each package must prove:

- exact PHP version, architecture, ABI, and required extension set;
- signed PHAR execution;
- SQLite, ZIP, GMP, sockets, OpenSSL, cURL, and CA configuration;
- successful valid TLS and rejected invalid TLS;
- LevelDB CRUD, batches, close/reopen, locking, and compression fixtures;
- relocation to a different absolute directory;
- package manifest, licenses, checksums, and absence of undeclared files; and
- Bedriox CLI startup, discovery, and clean shutdown for an integrated release.

Fixtures must be independent witnesses. Do not validate a production reader
only with bytes produced by the production writer.

## Security and release rules

Workflows use minimal permissions and immutable third-party action revisions.
Release credentials must never be exposed to untrusted pull-request code.
Archives reject traversal, escaping links, unexpected file types, and bounded
size violations.

Logs, manifests, packages, and crash output must not contain credentials,
tokens, signing keys, environment dumps, private workspace paths, or source
archive contents. The CA bundle is public trust material; private keys are not
part of Runtime.

Stable releases promote already qualified artifacts and never rebuild them.
Every artifact includes a SHA-256 checksum, machine-readable runtime manifest,
and required licenses/notices.

## Change preservation

Existing qualified targets and package contents are frozen unless the task
requires changing them. Characterize the current artifact before changing a
compiler, ABI, dependency, extension, flag, layout, certificate source, or
startup contract. Add a permanent regression at the last observable failure
boundary.

Keep changes in their owning area and avoid unrelated cleanup or dependency
updates. Do not patch generated build output. Never overwrite or remove a
system PHP installation, system certificate store, Visual Studio installation,
registry setting, firewall rule, or unrelated environment configuration.

## Validation and commits

Run the repository validation command documented in `README.md`, then inspect:

```shell
git status --short
git diff --name-only
git diff --stat
git diff --check
git diff
```

For a built target, run its complete package qualification suite. For Bedriox
integration changes, also run the Bedriox and workspace gates.

Keep commits focused with short, natural, imperative subjects. Never add
personal email addresses or identity trailers. A milestone is complete only
after focused tests, the complete applicable gate, pushed CI, and native target
qualification are green.
