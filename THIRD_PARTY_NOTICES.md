# Third-party notices

Bedriox Runtime downloads, builds, or packages the reviewed inputs below.
Versions and immutable digests are authoritative in
`manifests/dependencies.json` for packaged dependencies and
`fixtures/metadata.json` for independent test-fixture producers.

| Component | License | License copy |
| --- | --- | --- |
| PHP | PHP-3.01 | `licenses/php-PHP-3.01.txt` |
| PMMP LevelDB fork | BSD-3-Clause | `licenses/pmmp-leveldb-BSD-3-Clause.txt` |
| PMMP PHP LevelDB binding | PHP-3.01 | `licenses/pmmp-php-leveldb-PHP-3.01.txt` |
| curl CA Extract / Mozilla CA store | MPL-2.0 | `licenses/cacert-MPL-2.0.txt` |
| libsodium | ISC | `licenses/libsodium-ISC.txt` |
| libxml2 | MIT | `licenses/libxml2-MIT.txt` |
| Oniguruma | BSD-2-Clause | `licenses/oniguruma-BSD-2-Clause.txt` |
| zlib | Zlib | `licenses/zlib-Zlib.txt` |
| GMP | LGPL-3.0-or-later | `licenses/gmp-LGPL-3.0-or-later.txt` |
| curl | curl | `licenses/curl-curl.txt` |
| OpenSSL | Apache-2.0 | `licenses/openssl-Apache-2.0.txt` |
| libzip | BSD-3-Clause | `licenses/libzip-BSD-3-Clause.txt` |
| SQLite | Public domain blessing | `licenses/sqlite-blessing.txt` |
| zstd | BSD-3-Clause | `licenses/zstd-BSD-3-Clause.txt` |
| PHP SDK Binary Tools | BSD-2-Clause and bundled tool licenses | `licenses/php-sdk-binary-tools-BSD-2-Clause.txt` and the upstream archive |
| Brotli (PHP SDK package) | MIT | `licenses/brotli-MIT.txt` |
| libbzip2 (PHP SDK package) | bzip2-1.0.6 | `licenses/libbzip2-bzip2-1.0.6.txt` |
| libcurl (PHP SDK package) | curl | `licenses/curl-curl.txt` |
| libiconv (PHP SDK package) | LGPL-2.1-or-later | `licenses/libiconv-LGPL-2.1-or-later.txt` |
| liblzma (PHP SDK package) | 0BSD | `licenses/liblzma-0BSD.txt` |
| Oniguruma (PHP SDK package) | BSD-2-Clause | `licenses/oniguruma-BSD-2-Clause.txt` |
| libsodium (PHP SDK package) | ISC | `licenses/libsodium-ISC.txt` |
| libssh2 (PHP SDK package) | BSD-3-Clause | `licenses/libssh2-BSD-3-Clause.txt` |
| libzip (PHP SDK package) | BSD-3-Clause | `licenses/libzip-BSD-3-Clause.txt` |
| zstd (PHP SDK package) | BSD-3-Clause | `licenses/zstd-BSD-3-Clause.txt` |
| libxml2 (PHP SDK package) | MIT | `licenses/libxml2-MIT.txt` |
| MPIR (PHP SDK package) | LGPL-3.0-or-later | `licenses/gmp-LGPL-3.0-or-later.txt` |
| nghttp2 (PHP SDK package) | MIT | `licenses/nghttp2-MIT.txt` |
| OpenSSL (PHP SDK package) | Apache-2.0 | `licenses/openssl-Apache-2.0.txt` |
| SQLite (PHP SDK package) | Public domain blessing | `licenses/sqlite-blessing.txt` |
| zlib (PHP SDK package) | Zlib | `licenses/zlib-Zlib.txt` |
| CMake | BSD-3-Clause | `licenses/cmake-BSD-3-Clause.txt` |
| df-mc/goleveldb fixture producer | BSD-2-Clause | `licenses/df-mc-goleveldb-BSD-2-Clause.txt` |
| Google LevelDB fixture producer | BSD-3-Clause | `licenses/google-leveldb-BSD-3-Clause.txt` |
| Microsoft Visual C++ VC143 Runtime DLLs (Windows packages only) | Microsoft Visual Studio 2022 Software License Terms — Distributable Code | Notice included in Windows packages |

The Windows x86-64 PHP SDK packages above form the reviewed native dependency
closure for the configured PHP 8.4 build. Their exact archive URLs, sizes, and
SHA-256 digests are pinned in `manifests/dependencies.json`; the mutable PHP SDK
package index is not consumed as a build input.

Windows packages deploy the Microsoft VC143 runtime DLLs application-locally.
The build copies only the signed import closure, without modification, from
the active licensed Visual Studio 2022 Redist directory. These DLLs remain
subject to Microsoft's Visual Studio 2022 Software License Terms and
Distributable Code list; they are separate works and are not offered under
GPL-3.0-only.

Minecraft, Bedrock Edition, Mojang Studios, Microsoft, PHP, PMMP, and other
names belong to their respective owners. Bedriox is independent and is not
affiliated with or endorsed by those projects or organizations.
