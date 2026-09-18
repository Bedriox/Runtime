<?php

declare(strict_types=1);

final class ManifestModel
{
    private const TARGETS = [
        'windows-x86_64', 'linux-x86_64', 'linux-arm64',
        'macos-x86_64', 'macos-arm64', 'windows-arm64',
    ];

    private const SOURCE_IDS = [
        'php', 'leveldb', 'php-leveldb', 'ca-bundle', 'libsodium',
        'libxml2', 'oniguruma', 'zlib', 'gmp', 'curl', 'openssl',
        'libzip', 'sqlite', 'zstd', 'php-sdk-binary-tools',
        'php-sdk-dep-brotli', 'php-sdk-dep-libbzip2',
        'php-sdk-dep-libcurl', 'php-sdk-dep-libiconv',
        'php-sdk-dep-liblzma',
        'php-sdk-dep-libonig', 'php-sdk-dep-libsodium',
        'php-sdk-dep-libssh2', 'php-sdk-dep-libzip',
        'php-sdk-dep-libzstd', 'php-sdk-dep-libxml2',
        'php-sdk-dep-mpir',
        'php-sdk-dep-nghttp2', 'php-sdk-dep-openssl',
        'php-sdk-dep-sqlite3', 'php-sdk-dep-zlib',
        'cmake-windows-x86_64',
    ];

    private const TARGET_CLOSURE_IDS = ['windows-x86_64'];

    private const WINDOWS_X86_64_CLOSURE = [
        'php', 'leveldb', 'php-leveldb', 'ca-bundle',
        'php-sdk-binary-tools', 'cmake-windows-x86_64',
        'php-sdk-dep-brotli', 'php-sdk-dep-libbzip2',
        'php-sdk-dep-libcurl', 'php-sdk-dep-libiconv',
        'php-sdk-dep-liblzma',
        'php-sdk-dep-libonig', 'php-sdk-dep-libsodium',
        'php-sdk-dep-libssh2', 'php-sdk-dep-libzip',
        'php-sdk-dep-libzstd', 'php-sdk-dep-libxml2',
        'php-sdk-dep-mpir',
        'php-sdk-dep-nghttp2', 'php-sdk-dep-openssl',
        'php-sdk-dep-sqlite3', 'php-sdk-dep-zlib',
    ];

    private const SOURCE_FIELDS = [
        'version', 'commit', 'url', 'sha256', 'bytes', 'archiveType',
        'license', 'licenseFile', 'targets',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data, private readonly string $root)
    {
    }

    public static function load(string $path, ?string $root = null): self
    {
        if (!is_file($path) || ($contents = file_get_contents($path)) === false) {
            throw new RuntimeException('Dependency manifest is missing or unreadable.');
        }
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Dependency manifest is not valid JSON.', 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Dependency manifest root must be an object.');
        }
        $manifest = new self($data, $root ?? dirname($path, 2));
        $manifest->validate();
        return $manifest;
    }

    /** @return list<string> */
    public function targets(): array
    {
        /** @var list<string> */
        return $this->data['targets'];
    }

    public function sourceField(string $id, string $field): mixed
    {
        if (!in_array($id, self::SOURCE_IDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown source ID: %s', $id));
        }
        if (!in_array($field, self::SOURCE_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown source field: %s', $field));
        }
        /** @var array<string, mixed> $source */
        $source = $this->data['sources'][$id];
        if (!array_key_exists($field, $source)) {
            throw new InvalidArgumentException(sprintf('Source %s has no %s field.', $id, $field));
        }
        return $source[$field];
    }

    /** @return list<string> */
    public function targetClosure(string $target): array
    {
        if (!in_array($target, self::TARGET_CLOSURE_IDS, true)) {
            throw new InvalidArgumentException(sprintf('Target %s has no reviewed source closure.', $target));
        }
        /** @var list<string> */
        return $this->data['targetClosures'][$target];
    }

    private function validate(): void
    {
        self::exactKeys($this->data, ['schemaVersion', 'targets', 'targetClosures', 'sources'], 'manifest');
        if ($this->data['schemaVersion'] !== 1) {
            throw new RuntimeException('Unsupported dependency manifest schema version.');
        }
        if ($this->data['targets'] !== self::TARGETS) {
            throw new RuntimeException('Target set or ordering does not match the supported matrix.');
        }
        if (!is_array($this->data['sources']) || array_keys($this->data['sources']) !== self::SOURCE_IDS) {
            throw new RuntimeException('Source set or ordering does not match the reviewed set.');
        }
        foreach ($this->data['sources'] as $id => $source) {
            if (!is_array($source)) {
                throw new RuntimeException(sprintf('Source %s must be an object.', $id));
            }
            $this->validateSource((string) $id, $source);
        }
        if (!is_array($this->data['targetClosures']) || array_keys($this->data['targetClosures']) !== self::TARGET_CLOSURE_IDS) {
            throw new RuntimeException('Target closure set or ordering does not match the reviewed set.');
        }
        foreach ($this->data['targetClosures'] as $target => $closure) {
            if (!is_array($closure) || $closure === [] || !array_is_list($closure) || array_values(array_unique($closure)) !== $closure) {
                throw new RuntimeException(sprintf('Target closure %s must be a non-empty unique list.', $target));
            }
            foreach ($closure as $id) {
                if (!is_string($id) || !in_array($id, self::SOURCE_IDS, true)) {
                    throw new RuntimeException(sprintf('Target closure %s contains an unknown source.', $target));
                }
                /** @var array<string, mixed> $source */
                $source = $this->data['sources'][$id];
                if (!in_array($target, $source['targets'], true)) {
                    throw new RuntimeException(sprintf('Target closure %s contains source %s without matching target support.', $target, $id));
                }
            }
        }
        if ($this->data['targetClosures']['windows-x86_64'] !== self::WINDOWS_X86_64_CLOSURE) {
            throw new RuntimeException('Windows x86-64 source closure does not match the reviewed PHP 8.4 build inputs.');
        }
        if ($this->sourceField('php', 'version') !== '8.4.25') {
            throw new RuntimeException('The supported PHP pin must be exactly 8.4.25.');
        }
    }

    /** @param array<string, mixed> $source */
    private function validateSource(string $id, array $source): void
    {
        $references = array_values(array_intersect(['version', 'commit'], array_keys($source)));
        if (count($references) !== 1) {
            throw new RuntimeException(sprintf('Source %s must declare exactly one version or commit.', $id));
        }
        self::exactKeys($source, array_merge([$references[0]], array_slice(self::SOURCE_FIELDS, 2)), 'source ' . $id);
        $reference = $source[$references[0]];
        if (!is_string($reference) || $reference === '') {
            throw new RuntimeException(sprintf('Source %s has an invalid immutable reference.', $id));
        }
        if ($references[0] === 'commit' && preg_match('/^[0-9a-f]{40}$/D', $reference) !== 1) {
            throw new RuntimeException(sprintf('Source %s commit must be a full lowercase SHA-1.', $id));
        }
        $url = $source['url'];
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new RuntimeException(sprintf('Source %s URL must be valid HTTPS.', $id));
        }
        if (parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException(sprintf('Source %s URL must not contain a query or fragment.', $id));
        }
        if (!is_string($source['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $source['sha256']) !== 1) {
            throw new RuntimeException(sprintf('Source %s has an invalid SHA-256.', $id));
        }
        if (!is_int($source['bytes']) || $source['bytes'] < 1 || $source['bytes'] > 500_000_000) {
            throw new RuntimeException(sprintf('Source %s has an invalid size bound.', $id));
        }
        if (!in_array($source['archiveType'], ['tar.gz', 'tar.xz', 'zip', 'pem', 'text'], true)) {
            throw new RuntimeException(sprintf('Source %s has an unsupported archive type.', $id));
        }
        if (!is_string($source['license']) || preg_match('/^[A-Za-z0-9.+-]+$/D', $source['license']) !== 1) {
            throw new RuntimeException(sprintf('Source %s has an invalid license identifier.', $id));
        }
        if (!is_string($source['licenseFile']) || preg_match('#^licenses/[A-Za-z0-9.+_-]+\.txt$#D', $source['licenseFile']) !== 1) {
            throw new RuntimeException(sprintf('Source %s has an unsafe license path.', $id));
        }
        $license = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source['licenseFile']);
        if (!is_file($license) || filesize($license) === 0) {
            throw new RuntimeException(sprintf('Source %s license file is missing or empty.', $id));
        }
        if (!is_array($source['targets']) || $source['targets'] === [] || array_values(array_unique($source['targets'])) !== $source['targets']) {
            throw new RuntimeException(sprintf('Source %s targets must be a non-empty unique list.', $id));
        }
        foreach ($source['targets'] as $target) {
            if (!is_string($target) || !in_array($target, self::TARGETS, true)) {
                throw new RuntimeException(sprintf('Source %s declares an unsupported target.', $id));
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $context): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf('%s contains missing or unknown fields.', ucfirst($context)));
        }
    }
}
