<?php

declare(strict_types=1);

final class LevelDbTableInspector
{
    private const FOOTER_BYTES = 48;
    private const BLOCK_TRAILER_BYTES = 5;
    private const TABLE_MAGIC = "\x57\xfb\x80\x8b\x24\x75\x47\xdb";
    private const MAX_TABLE_BYTES = 67_108_864;
    private const MAX_BLOCKS = 65_536;
    private const MAX_BLOCK_ENTRIES = 1_000_000;

    /**
     * @return array{files: int, compressionTypes: list<int>, tables: list<array<string, mixed>>}
     */
    public static function inspectDatabase(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("LevelDB fixture directory does not exist: $directory");
        }
        $paths = array_merge(
            glob($directory . DIRECTORY_SEPARATOR . '*.ldb') ?: [],
            glob($directory . DIRECTORY_SEPARATOR . '*.sst') ?: [],
        );
        sort($paths, SORT_STRING);
        if ($paths === [] || count($paths) > self::MAX_BLOCKS) {
            throw new RuntimeException('LevelDB fixture has an invalid table-file count.');
        }

        $types = [];
        $tables = [];
        foreach ($paths as $path) {
            $table = self::inspectTable($path);
            $tables[] = $table;
            foreach ($table['compressionTypes'] as $type) {
                $types[$type] = true;
            }
        }
        $compressionTypes = array_map(intval(...), array_keys($types));
        sort($compressionTypes, SORT_NUMERIC);

        return ['files' => count($paths), 'compressionTypes' => $compressionTypes, 'tables' => $tables];
    }

    /**
     * @return array{
     *   path: string,
     *   bytes: int,
     *   compressionTypes: list<int>,
     *   metaindexBlock: array{offset: int, size: int, trailerOffset: int, type: int},
     *   indexBlock: array{offset: int, size: int, trailerOffset: int, type: int},
     *   auxiliaryBlocks: list<array{offset: int, size: int, trailerOffset: int, type: int}>,
     *   indexEntries: list<array{valueOffset: int, valueLength: int}>,
     *   dataBlocks: list<array{offset: int, size: int, trailerOffset: int, type: int}>
     * }
     */
    public static function inspectTable(string $path): array
    {
        $size = filesize($path);
        if (!is_int($size) || $size < self::FOOTER_BYTES || $size > self::MAX_TABLE_BYTES) {
            throw new RuntimeException('LevelDB table size is outside the admitted range.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new RuntimeException('Could not read the complete LevelDB table.');
        }

        $footerOffset = $size - self::FOOTER_BYTES;
        if (substr($bytes, -8) !== self::TABLE_MAGIC) {
            throw new RuntimeException('LevelDB table footer magic is invalid.');
        }
        $cursor = $footerOffset;
        $metaindexHandle = self::readHandle($bytes, $cursor, $footerOffset + 40, $footerOffset);
        $indexHandle = self::readHandle($bytes, $cursor, $footerOffset + 40, $footerOffset);
        if (trim(substr($bytes, $cursor, ($footerOffset + 40) - $cursor), "\0") !== '') {
            throw new RuntimeException('LevelDB table footer padding is non-zero.');
        }
        self::assertSeparate($metaindexHandle, $indexHandle);

        $metaindex = self::readPhysicalBlock($bytes, $metaindexHandle, $footerOffset);
        $index = self::readPhysicalBlock($bytes, $indexHandle, $footerOffset);
        $metaindexPayload = self::decodeInspectableBlock($metaindex);
        $indexPayload = self::decodeInspectableBlock($index);
        $metaindexEntries = self::parseBlockEntries(
            $metaindexPayload,
            $metaindex['type'] === 0 ? $metaindex['offset'] : null,
        );
        $indexEntries = self::parseBlockEntries($indexPayload, $index['type'] === 0 ? $index['offset'] : null);
        if ($indexEntries === [] || count($indexEntries) > self::MAX_BLOCKS) {
            throw new RuntimeException('LevelDB table index has an invalid entry count.');
        }

        $auxiliaryBlocks = [];
        $types = [$metaindex['type'] => true, $index['type'] => true];
        foreach ($metaindexEntries as $entry) {
            $handleCursor = 0;
            $handle = self::readHandle($entry['value'], $handleCursor, strlen($entry['value']), $footerOffset);
            if ($handleCursor !== strlen($entry['value'])) {
                throw new RuntimeException('LevelDB metaindex entry has trailing block-handle bytes.');
            }
            self::assertSeparate($handle, $metaindexHandle);
            self::assertSeparate($handle, $indexHandle);
            $block = self::readPhysicalBlock($bytes, $handle, $footerOffset);
            $types[$block['type']] = true;
            $auxiliaryBlocks[] = self::publicBlock($block);
        }

        $dataBlocks = [];
        $previousEnd = 0;
        foreach ($indexEntries as $entry) {
            $handleCursor = 0;
            $handle = self::readHandle($entry['value'], $handleCursor, strlen($entry['value']), $footerOffset);
            if ($handleCursor !== strlen($entry['value'])) {
                throw new RuntimeException('LevelDB index entry has trailing block-handle bytes.');
            }
            if ($handle['offset'] < $previousEnd) {
                throw new RuntimeException('LevelDB data block handles overlap or are not ordered.');
            }
            self::assertSeparate($handle, $metaindexHandle);
            self::assertSeparate($handle, $indexHandle);
            $block = self::readPhysicalBlock($bytes, $handle, $footerOffset);
            if ($block['type'] === 0) {
                self::parseBlockEntries($block['payload'], $block['offset']);
            } elseif ($block['type'] === 4) {
                self::parseBlockEntries(self::decodeInspectableBlock($block), null);
            }
            $types[$block['type']] = true;
            $dataBlocks[] = self::publicBlock($block);
            $previousEnd = $handle['offset'] + $handle['size'] + self::BLOCK_TRAILER_BYTES;
        }
        $compressionTypes = array_map(intval(...), array_keys($types));
        sort($compressionTypes, SORT_NUMERIC);

        return [
            'path' => $path,
            'bytes' => $size,
            'compressionTypes' => $compressionTypes,
            'metaindexBlock' => self::publicBlock($metaindex),
            'indexBlock' => self::publicBlock($index),
            'auxiliaryBlocks' => $auxiliaryBlocks,
            'indexEntries' => array_map(
                static fn (array $entry): array => [
                    'valueOffset' => $entry['valueOffset'],
                    'valueLength' => strlen($entry['value']),
                ],
                $indexEntries,
            ),
            'dataBlocks' => $dataBlocks,
        ];
    }

    public static function crc32c(string $bytes): int
    {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($index = 0; $index < 256; $index++) {
                $crc = $index;
                for ($bit = 0; $bit < 8; $bit++) {
                    $crc = (($crc & 1) !== 0 ? (($crc >> 1) ^ 0x82f63b78) : ($crc >> 1)) & 0xffffffff;
                }
                $table[$index] = $crc;
            }
        }
        $crc = 0xffffffff;
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length; $offset++) {
            $crc = (($crc >> 8) ^ $table[($crc ^ ord($bytes[$offset])) & 0xff]) & 0xffffffff;
        }

        return ($crc ^ 0xffffffff) & 0xffffffff;
    }

    public static function maskedCrc32c(string $bytes): int
    {
        $crc = self::crc32c($bytes);
        $rotated = (($crc >> 15) | (($crc << 17) & 0xffffffff)) & 0xffffffff;

        return ($rotated + 0xa282ead8) & 0xffffffff;
    }

    /** @param array{offset: int, size: int} $handle */
    private static function readPhysicalBlock(string $table, array $handle, int $footerOffset): array
    {
        $end = $handle['offset'] + $handle['size'];
        if ($handle['size'] < 1 || $end < $handle['offset'] || $end + self::BLOCK_TRAILER_BYTES > $footerOffset) {
            throw new RuntimeException('LevelDB block handle escapes the table body.');
        }
        $payload = substr($table, $handle['offset'], $handle['size']);
        $type = ord($table[$end]);
        if (!in_array($type, [0, 1, 2, 3, 4], true)) {
            throw new RuntimeException("LevelDB block uses unknown compression type $type.");
        }
        $stored = unpack('Vcrc', substr($table, $end + 1, 4));
        if (!is_array($stored) || $stored['crc'] !== self::maskedCrc32c($payload . chr($type))) {
            throw new RuntimeException('LevelDB block CRC32C does not match.');
        }

        return [
            'offset' => $handle['offset'],
            'size' => $handle['size'],
            'trailerOffset' => $end,
            'type' => $type,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array{key: string, value: string, valueOffset: int}>
     */
    private static function parseBlockEntries(string $block, ?int $tableOffset): array
    {
        $length = strlen($block);
        if ($length < 4) {
            throw new RuntimeException('LevelDB block is shorter than its restart count.');
        }
        $restartCountBytes = unpack('Vcount', substr($block, -4));
        if (!is_array($restartCountBytes)) {
            throw new RuntimeException('Could not decode LevelDB restart count.');
        }
        $restartCount = $restartCountBytes['count'];
        if ($restartCount < 1 || $restartCount > self::MAX_BLOCK_ENTRIES) {
            throw new RuntimeException('LevelDB block has an invalid restart count.');
        }
        $restartBytes = $restartCount * 4;
        $restartStart = $length - 4 - $restartBytes;
        if ($restartStart < 0) {
            throw new RuntimeException('LevelDB restart array escapes its block.');
        }
        $restarts = [];
        for ($index = 0; $index < $restartCount; $index++) {
            $decoded = unpack('Voffset', substr($block, $restartStart + ($index * 4), 4));
            if (!is_array($decoded)) {
                throw new RuntimeException('Could not decode a LevelDB restart offset.');
            }
            $restart = $decoded['offset'];
            if (
                ($restartStart === 0 ? $restart !== 0 : $restart >= $restartStart)
                || ($index === 0 && $restart !== 0)
                || ($index > 0 && $restart <= $restarts[$index - 1])
            ) {
                throw new RuntimeException('LevelDB restart offsets are invalid.');
            }
            $restarts[] = $restart;
        }

        $entries = [];
        $entryOffsets = [];
        $cursor = 0;
        $previousKey = '';
        while ($cursor < $restartStart) {
            if (count($entries) >= self::MAX_BLOCK_ENTRIES) {
                throw new RuntimeException('LevelDB block contains too many entries.');
            }
            $entryOffset = $cursor;
            $shared = self::readVarint($block, $cursor, $restartStart, $restartStart);
            $unshared = self::readVarint($block, $cursor, $restartStart, $restartStart);
            $valueLength = self::readVarint($block, $cursor, $restartStart, $restartStart);
            if ($shared > strlen($previousKey) || $cursor + $unshared + $valueLength > $restartStart) {
                throw new RuntimeException('LevelDB block entry escapes its data region.');
            }
            $key = substr($previousKey, 0, $shared) . substr($block, $cursor, $unshared);
            $cursor += $unshared;
            $valueOffset = $cursor;
            $value = substr($block, $cursor, $valueLength);
            $cursor += $valueLength;
            $entryOffsets[$entryOffset] = $shared;
            $entries[] = [
                'key' => $key,
                'value' => $value,
                'valueOffset' => $tableOffset === null ? -1 : $tableOffset + $valueOffset,
            ];
            $previousKey = $key;
        }
        if ($cursor !== $restartStart) {
            throw new RuntimeException('LevelDB block entries do not end at the restart array.');
        }
        if ($entries === [] && $restarts === [0]) {
            return [];
        }
        foreach ($restarts as $restart) {
            if (!array_key_exists($restart, $entryOffsets) || $entryOffsets[$restart] !== 0) {
                throw new RuntimeException('LevelDB restart does not identify an unshared entry.');
            }
        }

        return $entries;
    }

    /** @return array{offset: int, size: int} */
    private static function readHandle(string $bytes, int &$cursor, int $limit, int $maximum): array
    {
        $offset = self::readVarint($bytes, $cursor, $limit, $maximum);
        $size = self::readVarint($bytes, $cursor, $limit, $maximum);

        return ['offset' => $offset, 'size' => $size];
    }

    private static function readVarint(string $bytes, int &$cursor, int $limit, int $maximum): int
    {
        $value = 0;
        $shift = 0;
        for ($count = 0; $count < 10; $count++) {
            if ($cursor >= $limit || $cursor >= strlen($bytes)) {
                throw new RuntimeException('LevelDB varint is truncated.');
            }
            $byte = ord($bytes[$cursor++]);
            if ($shift >= 56 && ($byte & 0x7f) !== 0) {
                throw new RuntimeException('LevelDB varint exceeds the admitted integer range.');
            }
            $value |= ($byte & 0x7f) << $shift;
            if ($value > $maximum) {
                throw new RuntimeException('LevelDB varint exceeds the admitted bound.');
            }
            if (($byte & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
        }
        throw new RuntimeException('LevelDB varint is overlong.');
    }

    private static function decodeInspectableBlock(array $block): string
    {
        if ($block['type'] === 0) {
            return $block['payload'];
        }
        if ($block['type'] === 4) {
            $inflated = @gzinflate($block['payload'], self::MAX_TABLE_BYTES);
            if (!is_string($inflated)) {
                throw new RuntimeException('LevelDB raw-DEFLATE block could not be decompressed.');
            }

            return $inflated;
        }
        throw new RuntimeException("LevelDB metadata uses unsupported compression type {$block['type']}.");
    }

    /** @param array{offset: int, size: int} $left @param array{offset: int, size: int} $right */
    private static function assertSeparate(array $left, array $right): void
    {
        $leftEnd = $left['offset'] + $left['size'] + self::BLOCK_TRAILER_BYTES;
        $rightEnd = $right['offset'] + $right['size'] + self::BLOCK_TRAILER_BYTES;
        if ($left['offset'] < $rightEnd && $right['offset'] < $leftEnd) {
            throw new RuntimeException('LevelDB metadata block handles overlap.');
        }
    }

    /** @return array{offset: int, size: int, trailerOffset: int, type: int} */
    private static function publicBlock(array $block): array
    {
        return [
            'offset' => $block['offset'],
            'size' => $block['size'],
            'trailerOffset' => $block['trailerOffset'],
            'type' => $block['type'],
        ];
    }
}
