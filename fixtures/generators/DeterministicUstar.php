<?php

declare(strict_types=1);

final class DeterministicUstar
{
    /** @param list<string> $excludedNames */
    public static function create(string $sourceDirectory, string $output, array $excludedNames = []): void
    {
        $source = realpath($sourceDirectory);
        if (!is_string($source) || !is_dir($source)) {
            throw new RuntimeException("Archive source does not exist: $sourceDirectory");
        }
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('Fixture archive source contains an unsupported entry.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
            if (in_array(basename($relative), $excludedNames, true) || str_ends_with($relative, '.log')) {
                continue;
            }
            if ($relative === '' || str_contains($relative, '..') || strlen($relative) > 99) {
                throw new RuntimeException("Fixture archive path is not USTAR-safe: $relative");
            }
            $entries[$relative] = $entry->isDir() ? 'directory' : 'file';
        }
        ksort($entries, SORT_STRING);

        $handle = fopen($output, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Could not create fixture archive: $output");
        }
        try {
            foreach ($entries as $relative => $type) {
                $archiveName = $type === 'directory' ? $relative . '/' : $relative;
                $path = $source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $size = $type === 'file' ? filesize($path) : 0;
                if (!is_int($size) || $size < 0 || $size > 67_108_864) {
                    throw new RuntimeException("Fixture archive entry has an invalid size: $relative");
                }
                self::writeAll($handle, self::header($archiveName, $size, $type));
                if ($type === 'file') {
                    $input = fopen($path, 'rb');
                    if ($input === false) {
                        throw new RuntimeException("Could not read fixture archive entry: $relative");
                    }
                    try {
                        $copied = stream_copy_to_stream($input, $handle);
                        if ($copied !== $size) {
                            throw new RuntimeException("Could not copy complete fixture archive entry: $relative");
                        }
                    } finally {
                        fclose($input);
                    }
                    $padding = (512 - ($size % 512)) % 512;
                    if ($padding > 0) {
                        self::writeAll($handle, str_repeat("\0", $padding));
                    }
                }
            }
            self::writeAll($handle, str_repeat("\0", 1024));
        } finally {
            fclose($handle);
        }
    }

    private static function header(string $name, int $size, string $type): string
    {
        $header = str_repeat("\0", 512);
        self::field($header, 0, 100, $name);
        self::field($header, 100, 8, self::octal($type === 'directory' ? 0755 : 0644, 7));
        self::field($header, 108, 8, self::octal(0, 7));
        self::field($header, 116, 8, self::octal(0, 7));
        self::field($header, 124, 12, self::octal($size, 11));
        self::field($header, 136, 12, self::octal(0, 11));
        self::field($header, 148, 8, '        ');
        self::field($header, 156, 1, $type === 'directory' ? '5' : '0');
        self::field($header, 257, 6, "ustar\0");
        self::field($header, 263, 2, '00');
        self::field($header, 265, 32, 'root');
        self::field($header, 297, 32, 'root');
        $checksum = array_sum(unpack('C*', $header));
        self::field($header, 148, 8, sprintf("%06o\0 ", $checksum));

        return $header;
    }

    private static function octal(int $value, int $digits): string
    {
        $encoded = sprintf("%0{$digits}o\0", $value);
        if (strlen($encoded) !== $digits + 1) {
            throw new RuntimeException('USTAR integer exceeds its field.');
        }

        return $encoded;
    }

    private static function field(string &$header, int $offset, int $length, string $value): void
    {
        if (strlen($value) > $length) {
            throw new RuntimeException('USTAR field value is too long.');
        }
        $header = substr_replace($header, str_pad($value, $length, "\0"), $offset, $length);
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes): void
    {
        $written = fwrite($handle, $bytes);
        if ($written !== strlen($bytes)) {
            throw new RuntimeException('Could not write the complete USTAR archive.');
        }
    }
}
