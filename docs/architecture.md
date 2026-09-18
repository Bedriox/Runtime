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
resolves extensions and `certs/cacert.pem` within the package. A generated
manifest records the target, architecture, PHP version, extension ABI, source
revisions, compiler, build flags, and packaged file hashes.

The runtime never installs globally, changes `PATH`, updates a certificate
store, or removes another PHP installation.

## Target maturity

Each target advances independently from declared to experimental and then to
supported. Native execution of the complete qualification suite is required
for support. Windows ARM64 remains experimental because upstream PHP 8.4's
official Windows builder does not currently qualify that architecture.
