<?php

declare(strict_types=1);

final class PackageArchive
{
    public static function create(string $format, string $packageRoot, string $archive): void
    {
        self::assertFormat($format);
        $files = self::packageFiles($packageRoot);
        self::assertNewArchivePath($archive);

        if ($format === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Could not create ZIP archive.');
            }
            try {
                foreach ($files as $relative => $metadata) {
                    if (!$zip->addFile($metadata['path'], 'bin/' . $relative)) {
                        throw new RuntimeException('Could not add file to ZIP: ' . $relative);
                    }
                }
            } catch (Throwable $exception) {
                $zip->close();
                @unlink($archive);
                throw $exception;
            }
            if (!$zip->close()) {
                @unlink($archive);
                throw new RuntimeException('Could not finalize ZIP archive.');
            }
            return;
        }

        if (!str_ends_with($archive, '.tar.gz')) {
            throw new RuntimeException('Tar archive name must end in .tar.gz.');
        }
        try {
            self::createTarGz($archive, $files);
        } catch (Throwable $exception) {
            @unlink($archive);
            throw $exception;
        }
    }

    public static function verifyAndExtract(string $format, string $archive, string $packageRoot, string $destination): string
    {
        self::assertFormat($format);
        $archiveReal = realpath($archive);
        if ($archiveReal === false || !is_file($archiveReal)) {
            throw new RuntimeException('Package archive is missing.');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Relocation destination already exists.');
        }
        if (realpath(dirname($destination)) === false) {
            throw new RuntimeException('Relocation destination parent does not exist.');
        }
        if (!mkdir($destination, 0755, false)) {
            throw new RuntimeException('Could not create relocation destination.');
        }

        $expected = self::packageFiles($packageRoot);
        $expectedInventory = [];
        foreach ($expected as $relative => $metadata) {
            $expectedInventory['bin/' . $relative] = ['bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256']];
        }
        ksort($expectedInventory, SORT_STRING);
        $actual = $format === 'zip'
            ? self::extractZip($archiveReal, $destination, $expectedInventory)
            : self::extractTar($archiveReal, $destination, $expectedInventory);
        ksort($actual, SORT_STRING);
        if ($actual !== $expectedInventory) {
            $difference = array_values(array_unique(array_merge(
                array_diff(array_keys($expectedInventory), array_keys($actual)),
                array_diff(array_keys($actual), array_keys($expectedInventory)),
                array_keys(array_filter(
                    $expectedInventory,
                    static fn (array $metadata, string $name): bool => isset($actual[$name]) && $actual[$name] !== $metadata,
                    ARRAY_FILTER_USE_BOTH,
                )),
            )));
            sort($difference, SORT_STRING);
            $first = $difference[0] ?? '';
            $detail = $first !== '' ? sprintf(
                ' (%s expected %s, archived %s)',
                $first,
                json_encode($expectedInventory[$first] ?? null, JSON_THROW_ON_ERROR),
                json_encode($actual[$first] ?? null, JSON_THROW_ON_ERROR),
            ) : '';
            throw new RuntimeException('Archive entries or hashes differ from the qualified package: ' . implode(', ', $difference) . $detail);
        }

        $relocated = $destination . DIRECTORY_SEPARATOR . 'bin';
        $executable = $relocated . DIRECTORY_SEPARATOR . ($format === 'zip' ? 'php.exe' : 'php');
        if (!is_file($executable)) {
            throw new RuntimeException('Extracted archive has no target PHP executable.');
        }
        if ($format === 'tar.gz' && PHP_OS_FAMILY !== 'Windows' && ((fileperms($executable) ?: 0) & 0111) === 0) {
            throw new RuntimeException('Extracted Unix PHP executable has no execute mode.');
        }
        return $relocated;
    }

    public static function publish(string $candidate, string $destination): void
    {
        if (!is_file($candidate) || is_link($candidate)) {
            throw new RuntimeException('Candidate archive is missing or unsupported.');
        }
        if (file_exists($destination) || is_link($destination) || realpath(dirname($destination)) === false) {
            throw new RuntimeException('Published archive destination is unavailable.');
        }
        if (!link($candidate, $destination)) {
            throw new RuntimeException('Could not publish archive without replacing an existing artifact.');
        }
        if (!unlink($candidate)) {
            @unlink($destination);
            throw new RuntimeException('Could not remove the temporary archive after publication.');
        }
    }

    public static function writeChecksum(string $archive, string $checksum, string $archiveName): string
    {
        $hash = hash_file('sha256', $archive);
        if ($hash === false) {
            throw new RuntimeException('Could not hash the published archive.');
        }
        $handle = @fopen($checksum, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not exclusively create the artifact checksum.');
        }
        $contents = $hash . '  ' . $archiveName . PHP_EOL;
        try {
            $written = fwrite($handle, $contents);
            if ($written !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Could not write the complete artifact checksum.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($checksum);
            throw $exception;
        }
        fclose($handle);
        return $hash;
    }

    /** @return array<string, array{path: string, bytes: int, sha256: string, mode: int}> */
    private static function packageFiles(string $root): array
    {
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new RuntimeException('Package root is missing.');
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) {
                throw new RuntimeException('Package contains an unsupported filesystem entry.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($rootReal) + 1));
            self::assertArchiveName('bin/' . $relative);
            if (str_ends_with(strtolower($relative), '.sha256')) {
                throw new RuntimeException('Checksum sidecars must remain outside bin and its archive.');
            }
            $hash = hash_file('sha256', $entry->getPathname());
            if ($hash === false) {
                throw new RuntimeException('Could not hash package file: ' . $relative);
            }
            $files[$relative] = [
                'path' => $entry->getPathname(),
                'bytes' => $entry->getSize(),
                'sha256' => $hash,
                'mode' => $entry->getPerms() & 0777,
            ];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, array{bytes: int, sha256: string}> */
    private static function extractZip(string $archive, string $destination, array $expected): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Could not open ZIP archive for verification.');
        }
        $inventory = [];
        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new RuntimeException('ZIP contains an unreadable entry.');
                }
                $name = $stat['name'];
                self::assertArchiveName($name);
                if (isset($inventory[$name])) {
                    throw new RuntimeException('ZIP contains a duplicate entry: ' . $name);
                }
                if (str_ends_with(strtolower($name), '.sha256')) {
                    throw new RuntimeException('ZIP contains an internal checksum sidecar.');
                }
                if (!isset($expected[$name]) || !isset($stat['size']) || $stat['size'] !== $expected[$name]['bytes']) {
                    throw new RuntimeException('ZIP contains an unexpected path or byte count: ' . $name);
                }
                $operations = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operations, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('ZIP contains a symbolic link: ' . $name);
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new RuntimeException('Could not read ZIP entry: ' . $name);
                }
                try {
                    [$bytes, $hash] = self::copyAndHash($stream, self::destinationPath($destination, $name));
                } finally {
                    fclose($stream);
                }
                $inventory[$name] = ['bytes' => $bytes, 'sha256' => $hash];
            }
        } finally {
            $zip->close();
        }
        return $inventory;
    }

    /** @return array<string, array{bytes: int, sha256: string}> */
    private static function extractTar(string $archive, string $destination, array $expected): array
    {
        $phar = new PharData($archive);
        $inventory = [];
        $prefix = 'phar://' . str_replace('\\', '/', $archive) . '/';
        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $entry) {
            if (!$entry instanceof PharFileInfo || $entry->isLink() || !$entry->isFile()) {
                throw new RuntimeException('Tar archive contains an unsupported entry.');
            }
            $pathname = str_replace('\\', '/', $entry->getPathname());
            if (!str_starts_with($pathname, $prefix)) {
                throw new RuntimeException('Tar archive entry has an unexpected path.');
            }
            $name = substr($pathname, strlen($prefix));
            self::assertArchiveName($name);
            if (isset($inventory[$name])) {
                throw new RuntimeException('Tar archive contains a duplicate entry: ' . $name);
            }
            if (str_ends_with(strtolower($name), '.sha256')) {
                throw new RuntimeException('Tar archive contains an internal checksum sidecar.');
            }
            if (!isset($expected[$name]) || $entry->getSize() !== $expected[$name]['bytes']) {
                throw new RuntimeException('Tar archive contains an unexpected path or byte count: ' . $name);
            }
            if ($name === 'bin/php' && ($entry->getPerms() & 0111) === 0) {
                throw new RuntimeException('Tar archive PHP executable has no execute mode.');
            }
            $target = self::destinationPath($destination, $name);
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not create extracted archive directory.');
            }
            if (!copy($entry->getPathname(), $target)) {
                throw new RuntimeException('Could not extract tar entry: ' . $name);
            }
            $bytes = filesize($target);
            $hash = hash_file('sha256', $target);
            if ($bytes === false || $hash === false) {
                throw new RuntimeException('Could not inventory extracted tar entry: ' . $name);
            }
            @chmod($target, $entry->getPerms() & 0777);
            $inventory[$name] = ['bytes' => $bytes, 'sha256' => $hash];
        }
        return $inventory;
    }

    /** @param resource $input @return array{int, string} */
    private static function copyAndHash($input, string $target): array
    {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Could not create extracted archive directory.');
        }
        $output = fopen($target, 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('Could not create extracted archive file.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read archive entry bytes.');
                }
                if ($chunk === '') {
                    break;
                }
                $written = fwrite($output, $chunk);
                if ($written !== strlen($chunk)) {
                    throw new RuntimeException('Could not write extracted archive bytes.');
                }
                hash_update($hash, $chunk);
                $bytes += $written;
            }
        } finally {
            fclose($output);
        }
        return [$bytes, hash_final($hash)];
    }

    private static function destinationPath(string $destination, string $name): string
    {
        $root = realpath($destination);
        if ($root === false) {
            throw new RuntimeException('Could not resolve extraction root.');
        }
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (!str_starts_with(strtolower(str_replace('\\', '/', $target)), strtolower($prefix))) {
            throw new RuntimeException('Archive entry escapes extraction root.');
        }
        return $target;
    }

    private static function assertArchiveName(string $name): void
    {
        if (
            preg_match('#^bin/[A-Za-z0-9._+/-]+$#D', $name) !== 1
            || str_contains($name, '\\')
            || in_array('.', explode('/', $name), true)
            || in_array('..', explode('/', $name), true)
            || str_ends_with($name, '/')
        ) {
            throw new RuntimeException('Archive contains an unsafe or non-file entry name.');
        }
    }

    private static function assertNewArchivePath(string $archive): void
    {
        $normalized = str_replace('\\', '/', $archive);
        if (
            preg_match('#^(?:[A-Za-z]:/|/)#D', $normalized) !== 1
            || preg_match('#(?:^|/)\.\.?(/|$)#', $normalized) === 1
            || realpath(dirname($archive)) === false
            || file_exists($archive)
            || is_link($archive)
        ) {
            throw new RuntimeException('Unsafe or unavailable archive output.');
        }
    }

    private static function assertFormat(string $format): void
    {
        if (!in_array($format, ['zip', 'tar.gz'], true)) {
            throw new InvalidArgumentException('Archive format must be zip or tar.gz.');
        }
    }

    /** @param array<string, array{path: string, bytes: int, sha256: string, mode: int}> $files */
    private static function createTarGz(string $archive, array $files): void
    {
        $claim = @fopen($archive, 'xb');
        if (!is_resource($claim)) {
            throw new RuntimeException('Could not exclusively claim tar.gz archive output.');
        }
        fclose($claim);
        $output = gzopen($archive, 'wb9');
        if (!is_resource($output)) {
            throw new RuntimeException('Could not create tar.gz archive.');
        }
        try {
            foreach ($files as $relative => $metadata) {
                $name = 'bin/' . $relative;
                $mode = $relative === 'php' ? ($metadata['mode'] | 0111) : $metadata['mode'];
                self::gzipWriteAll($output, self::tarHeader($name, $mode, $metadata['bytes']));
                $input = fopen($metadata['path'], 'rb');
                if (!is_resource($input)) {
                    throw new RuntimeException('Could not read package file for tar: ' . $relative);
                }
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException('Could not read package file for tar: ' . $relative);
                        }
                        if ($chunk === '') {
                            break;
                        }
                        self::gzipWriteAll($output, $chunk);
                    }
                } finally {
                    fclose($input);
                }
                $padding = (512 - ($metadata['bytes'] % 512)) % 512;
                if ($padding !== 0) {
                    self::gzipWriteAll($output, str_repeat("\0", $padding));
                }
            }
            self::gzipWriteAll($output, str_repeat("\0", 1024));
        } finally {
            gzclose($output);
        }
    }

    private static function tarHeader(string $name, int $mode, int $bytes): string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            $split = strrpos(substr($name, 0, 156), '/');
            if ($split === false || $split > 155 || strlen($name) - $split - 1 > 100) {
                throw new RuntimeException('Package path is too long for the tar format: ' . $name);
            }
            $prefix = substr($name, 0, $split);
            $name = substr($name, $split + 1);
        }
        $field = static fn (string $value, int $length): string => str_pad($value, $length, "\0");
        $octal = static fn (int $value, int $length): string => str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT) . "\0";
        $header = $field($name, 100)
            . $octal($mode & 0777, 8)
            . $octal(0, 8)
            . $octal(0, 8)
            . $octal($bytes, 12)
            . $octal(0, 12)
            . str_repeat(' ', 8)
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0"
            . '00'
            . $field('bedriox', 32)
            . $field('bedriox', 32)
            . $octal(0, 8)
            . $octal(0, 8)
            . $field($prefix, 155)
            . str_repeat("\0", 12);
        if (strlen($header) !== 512) {
            throw new RuntimeException('Could not construct tar header.');
        }
        $checksum = str_pad(decoct(array_sum(unpack('C*', $header))), 6, '0', STR_PAD_LEFT) . "\0 ";
        return substr_replace($header, $checksum, 148, 8);
    }

    /** @param resource $output */
    private static function gzipWriteAll($output, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = gzwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write tar.gz archive.');
            }
            $offset += $written;
        }
    }
}
