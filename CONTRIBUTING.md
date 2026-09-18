# Contributing to Bedriox Runtime

Runtime changes can affect every Bedriox installation, so a successful compile
is only the beginning of review.

Before changing a dependency, compiler, extension, configuration value, or
package path, read `AGENTS.md`, the relevant document under `docs/`, and
[RFC 0017](https://github.com/Bedriox/RFCs/blob/main/rfcs/0017-runtime-distribution.md).

Keep each change focused. Pin all new external inputs, record their SHA-256
digests and licenses, add failure coverage, and run:

```shell
php tools/validate.php
```

Target-specific changes must also build and pass the native qualification suite
on that target. Do not commit downloads, extracted sources, build trees,
artifacts, credentials, private paths, or local agent instructions.

Use the private security process in [`SECURITY.md`](SECURITY.md) for suspected
vulnerabilities rather than a public issue.
