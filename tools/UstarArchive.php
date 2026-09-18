<?php

declare(strict_types=1);

final class UstarArchive
{
    private const MAX_ENTRIES = 4096;
    private const MAX_ARCHIVE_BYTES = 67_108_864;
    private const MAX_TOTAL_BYTES = 67_108_864;

    /**
     * @return list<array{name: string, type: string, bytes: string}>
     */
    public static function read(string $path): array
    {
        $archiveSize = is_file($path) ? filesize($path) : false;
        if (!is_int($archiveSize) || $archiveSize < 1024 || $archiveSize > self::MAX_ARCHIVE_BYTES || $archiveSize % 512 !== 0) {
            throw new RuntimeException('USTAR fixture has an invalid size.');
        }
        $archive = file_get_contents($path);
        if (!is_string($archive) || strlen($archive) !== $archiveSize) {
            throw new RuntimeException('USTAR fixture has an invalid size.');
        }
        $entries = [];
        $names = [];
        $offset = 0;
        $totalBytes = 0;
        while ($offset + 512 <= strlen($archive)) {
            $header = substr($archive, $offset, 512);
            $offset += 512;
            if ($header === str_repeat("\0", 512)) {
                if (substr($archive, $offset, 512) !== str_repeat("\0", 512)) {
                    throw new RuntimeException('USTAR fixture has only one terminating zero block.');
                }
                $offset += 512;
                if (trim(substr($archive, $offset), "\0") !== '') {
                    throw new RuntimeException('USTAR fixture contains data after its terminator.');
                }
                return $entries;
            }
            if (count($entries) >= self::MAX_ENTRIES || substr($header, 257, 8) !== "ustar\0" . '00') {
                throw new RuntimeException('USTAR fixture header is invalid.');
            }
            $storedChecksum = self::octal(substr($header, 148, 8));
            $checksumHeader = substr_replace($header, '        ', 148, 8);
            if ($storedChecksum !== array_sum(unpack('C*', $checksumHeader))) {
                throw new RuntimeException('USTAR fixture header checksum does not match.');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $canonicalName = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
            if (!self::isSafeEntryName($canonicalName)) {
                throw new RuntimeException('USTAR fixture contains an unsafe or duplicate path.');
            }
            $caseFoldedName = strtolower($canonicalName);
            if (isset($names[$caseFoldedName])) {
                throw new RuntimeException('USTAR fixture contains an unsafe or duplicate path.');
            }
            $names[$caseFoldedName] = true;
            $typeByte = $header[156];
            $type = $typeByte === '5' ? 'directory' : ($typeByte === '0' || $typeByte === "\0" ? 'file' : '');
            if ($type === '') {
                throw new RuntimeException('USTAR fixture contains an unsupported entry type.');
            }
            $size = self::octal(substr($header, 124, 12));
            if (($type === 'directory' && $size !== 0) || $offset + $size > strlen($archive)) {
                throw new RuntimeException('USTAR fixture entry has an invalid size.');
            }
            foreach ([[100, 8, $type === 'directory' ? 0755 : 0644], [108, 8, 0], [116, 8, 0], [136, 12, 0]] as [$fieldOffset, $length, $expected]) {
                if (self::octal(substr($header, $fieldOffset, $length)) !== $expected) {
                    throw new RuntimeException('USTAR fixture metadata is not deterministic.');
                }
            }
            if (rtrim(substr($header, 265, 32), "\0") !== 'root' || rtrim(substr($header, 297, 32), "\0") !== 'root') {
                throw new RuntimeException('USTAR fixture owner metadata is not deterministic.');
            }
            $bytes = substr($archive, $offset, $size);
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('USTAR fixture exceeds its expanded-size limit.');
            }
            $offset += $size;
            $padding = (512 - ($size % 512)) % 512;
            if (substr($archive, $offset, $padding) !== str_repeat("\0", $padding)) {
                throw new RuntimeException('USTAR fixture entry padding is non-zero.');
            }
            $offset += $padding;
            $entries[] = ['name' => $canonicalName, 'type' => $type, 'bytes' => $bytes];
        }
        throw new RuntimeException('USTAR fixture has no complete terminator.');
    }

    public static function extract(string $archive, string $destination): void
    {
        if (file_exists($destination) || is_link($destination) || !mkdir($destination, 0777, true)) {
            throw new RuntimeException("USTAR extraction destination must be new: $destination");
        }
        $destinationRoot = realpath($destination);
        if (!is_string($destinationRoot)) {
            throw new RuntimeException('Could not resolve the USTAR extraction destination.');
        }
        $rootPrefix = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destinationRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
        foreach (self::read($archive) as $entry) {
            $target = $destinationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
            $lexicalTarget = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target));
            if (!str_starts_with($lexicalTarget, $rootPrefix)) {
                throw new RuntimeException("USTAR entry escapes its extraction destination: {$entry['name']}");
            }
            if ($entry['type'] === 'directory') {
                if (!mkdir($target, 0777, true) && !is_dir($target)) {
                    throw new RuntimeException("Could not create USTAR directory: {$entry['name']}");
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("Could not create USTAR parent: {$entry['name']}");
            }
            if (file_put_contents($target, $entry['bytes'], LOCK_EX) !== strlen($entry['bytes'])) {
                throw new RuntimeException("Could not extract USTAR file: {$entry['name']}");
            }
        }
    }

    private static function isSafeEntryName(string $name): bool
    {
        if (
            $name === ''
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || str_contains($name, ':')
            || preg_match('/[\x00-\x1f\x7f]/D', $name) === 1
        ) {
            return false;
        }
        foreach (explode('/', $name) as $component) {
            if (
                $component === ''
                || $component === '.'
                || $component === '..'
                || str_ends_with($component, '.')
                || str_ends_with($component, ' ')
                || preg_match('/^(?:con|prn|aux|nul|conin\$|conout\$|com[0-9]|lpt[0-9])(?:\.|$)/iD', $component) === 1
            ) {
                return false;
            }
        }
        return true;
    }

    private static function octal(string $field): int
    {
        $trimmed = trim($field, " \0");
        if ($trimmed === '' || preg_match('/^[0-7]+$/D', $trimmed) !== 1) {
            throw new RuntimeException('USTAR fixture contains an invalid octal field.');
        }

        return intval($trimmed, 8);
    }
}
