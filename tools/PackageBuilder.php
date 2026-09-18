<?php

declare(strict_types=1);

final class PackageBuilder
{
    /** @var list<string> */
    private const TARGETS = [
        'windows-x86_64', 'linux-x86_64', 'linux-arm64',
        'macos-x86_64', 'macos-arm64', 'windows-arm64',
    ];

    public static function assertTarget(string $target): void
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException('Unsupported package target: ' . $target);
        }
    }

    public static function assertAbsolutePath(string $path, string $description): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException($description . ' must be a non-empty absolute path.');
        }
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('#^(?:[A-Za-z]:/|/)#D', $normalized) !== 1 || preg_match('#(?:^|/)\.\.?(/|$)#', $normalized) === 1) {
            throw new InvalidArgumentException($description . ' must be absolute and contain no unresolved segments.');
        }
        if (preg_match('#^(?:[A-Za-z]:)?/$#D', $normalized) === 1) {
            throw new InvalidArgumentException($description . ' must not be a filesystem root.');
        }
        return rtrim($path, '/\\');
    }

    public static function assertNewDirectoryBelow(string $path, string $parent, string $description): void
    {
        $path = self::assertAbsolutePath($path, $description);
        $parentReal = realpath(self::assertAbsolutePath($parent, $description . ' parent'));
        if ($parentReal === false || !is_dir($parentReal)) {
            throw new RuntimeException($description . ' parent does not exist.');
        }
        $candidateParent = realpath(dirname($path));
        if ($candidateParent === false) {
            throw new RuntimeException($description . ' immediate parent does not exist.');
        }
        $prefix = rtrim(str_replace('\\', '/', $parentReal), '/') . '/';
        $candidate = str_replace('\\', '/', $candidateParent) . '/' . basename($path) . '/';
        if (!str_starts_with(strtolower($candidate), strtolower($prefix))) {
            throw new RuntimeException($description . ' escapes its bounded parent.');
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException($description . ' already exists: ' . $path);
        }
    }

    public static function assertExistingDirectoryBelow(string $path, string $parent, string $description): string
    {
        $resolvedPath = realpath(self::assertAbsolutePath($path, $description));
        $resolvedParent = realpath(self::assertAbsolutePath($parent, $description . ' parent'));
        if ($resolvedPath === false || !is_dir($resolvedPath) || $resolvedParent === false || !is_dir($resolvedParent)) {
            throw new RuntimeException($description . ' or its bounded parent does not exist.');
        }
        $prefix = rtrim(str_replace('\\', '/', $resolvedParent), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $resolvedPath), '/') . '/';
        if (!str_starts_with(strtolower($candidate), strtolower($prefix))) {
            throw new RuntimeException($description . ' escapes its bounded parent.');
        }
        return $resolvedPath;
    }

    public static function copyTree(string $source, string $destination): void
    {
        $sourceReal = realpath($source);
        if ($sourceReal === false || !is_dir($sourceReal)) {
            throw new RuntimeException('Native runtime source directory is missing.');
        }
        if (is_link($sourceReal)) {
            throw new RuntimeException('Native runtime source must not be a symbolic link.');
        }
        if (!mkdir($destination, 0755, false)) {
            throw new RuntimeException('Could not create package directory.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Native runtime contains a symbolic link: ' . $entry->getPathname());
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($sourceReal) + 1));
            self::assertRelativePath($relative);
            if (str_ends_with(strtolower($relative), '.sha256')) {
                throw new RuntimeException('Native runtime contains an undeclared checksum sidecar: ' . $relative);
            }
            if ($relative === 'php.ini' || $relative === 'runtime-manifest.json') {
                continue;
            }
            $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($entry->isDir()) {
                if (!mkdir($target, 0755, false) && !is_dir($target)) {
                    throw new RuntimeException('Could not create package directory: ' . $relative);
                }
            } elseif ($entry->isFile()) {
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new RuntimeException('Could not create package file parent: ' . $relative);
                }
                if (!copy($entry->getPathname(), $target)) {
                    throw new RuntimeException('Could not copy native runtime file: ' . $relative);
                }
                @chmod($target, $entry->getPerms() & 0777);
            } else {
                throw new RuntimeException('Native runtime contains an unsupported filesystem entry: ' . $relative);
            }
        }
    }

    public static function renderIni(string $template, string $packageRoot): string
    {
        if (!str_contains($template, '@BEDRIOX_DYNAMIC_EXTENSIONS@')) {
            throw new RuntimeException('PHP configuration template has no dynamic extension marker.');
        }
        $extensionRoot = $packageRoot . DIRECTORY_SEPARATOR . 'ext';
        $lines = [];
        if (is_dir($extensionRoot)) {
            $files = scandir($extensionRoot);
            if ($files === false) {
                throw new RuntimeException('Could not inspect packaged extension directory.');
            }
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $extensionRoot . DIRECTORY_SEPARATOR . $file;
                if (is_link($path) || !is_file($path)) {
                    throw new RuntimeException('Extension directory contains an unsupported entry: ' . $file);
                }
                if (preg_match('/^(?:php_)?opcache\.(?:dll|so|dylib)$/iD', $file) === 1) {
                    $lines[] = 'zend_extension = "${BEDRIOX_RUNTIME_ROOT}/ext/' . $file . '"';
                } elseif (preg_match('/^(?:php_)?[A-Za-z0-9_+-]+\.(?:dll|so|dylib)$/D', $file) === 1) {
                    $lines[] = 'extension = "${BEDRIOX_RUNTIME_ROOT}/ext/' . $file . '"';
                }
            }
        }
        sort($lines, SORT_STRING);
        return str_replace('@BEDRIOX_DYNAMIC_EXTENSIONS@', implode(PHP_EOL, $lines), $template);
    }

    /** @return array<string, array{bytes: int, sha256: string}> */
    public static function inventory(string $root): array
    {
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new RuntimeException('Package root is unavailable for inventory.');
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) {
                throw new RuntimeException('Package contains an unsupported filesystem entry.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($rootReal) + 1));
            self::assertRelativePath($relative);
            if (str_ends_with(strtolower($relative), '.sha256')) {
                throw new RuntimeException('Package contains an undeclared checksum sidecar: ' . $relative);
            }
            if ($relative === 'runtime-manifest.json') {
                continue;
            }
            $digest = hash_file('sha256', $entry->getPathname());
            if ($digest === false) {
                throw new RuntimeException('Could not hash packaged file: ' . $relative);
            }
            $files[$relative] = ['bytes' => $entry->getSize(), 'sha256' => $digest];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function assertIdentity(string $identity, string $description): string
    {
        $identity = trim($identity);
        if ($identity === '' || strlen($identity) > 500 || str_contains($identity, "\n") || str_contains($identity, "\r") || preg_match('#(?:[A-Za-z]:[\\/]|/(?:Users|home|root|workspace|build|private|tmp)/)#i', $identity) === 1) {
            throw new InvalidArgumentException($description . ' identity is empty, multiline, oversized, or path-bearing.');
        }
        return $identity;
    }

    private static function assertRelativePath(string $path): void
    {
        if (preg_match('#^[A-Za-z0-9._+/-]+$#D', $path) !== 1 || str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new RuntimeException('Unsafe package path: ' . $path);
        }
        $segments = explode('/', $path);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException('Unsafe package path: ' . $path);
        }
    }
}
