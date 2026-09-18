# Runtime Architecture

Runtime turns a reviewed dependency manifest into a relocatable PHP package.
It has no gameplay or Bedrock packet responsibilities.

## Pipeline

```text
immutable manifest
    -> toolchain preflight
    -> verified download cache
    -> isolated source and build trees
    -> staged PHP and native extensions
    -> package configuration and licenses
    -> native qualification
    -> checksums and release artifact
```

Downloads, source extraction, compilation, installation, staging, and release
artifacts use separate repository-local roots. Build code validates a resolved
path before removing or replacing anything under those roots.

## Ownership boundaries

Runtime owns the interpreter and native capability set. The Bedriox repository
owns `start.cmd`, `start.ps1`, `start.sh`, server configuration, and the final
installation into its `bin/` directory. The Bedriox world provider owns how
LevelDB keys and values represent worlds; Runtime only proves that the native
database implementation can read and write the required compression formats.

## Package contract

Packages are relocatable and do not contain a build-machine path. `php.ini`
resolves extensions and `certs/cacert.pem` within the package, while the
launcher points `OPENSSL_CONF` at the packaged relocatable provider
configuration. A generated schema-2 manifest records the target, architecture,
PHP version, thread-safety choice, extension ABI, source revisions, compiler,
build flags, and packaged file hashes.

The runtime never installs globally, changes `PATH`, updates a certificate
store, or removes another PHP installation.
Mutable opcode cache entries live outside the immutable package under the
launcher-supplied `BEDRIOX_RUNTIME_CACHE`; they are not part of the package
inventory or trust boundary.

## Target maturity

Each target advances independently from declared to experimental and then to
supported. Native execution of the complete qualification suite is required
for support. Windows ARM64 remains experimental because upstream PHP 8.4's
official Windows builder does not currently qualify that architecture.
