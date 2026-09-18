# Changelog

## Unreleased

- Established the Runtime build, dependency, packaging, qualification, and
  release foundation for PHP 8.4.
- Hardened TLS qualification to exercise cURL and OpenSSL through the packaged
  default CA configuration and distinguish verification rejection from network
  reachability failures.
- Preserved static libzip Zstandard support by retaining its compression
  libraries at the end of the Unix PHP link plan.
