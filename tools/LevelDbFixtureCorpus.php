<?php

declare(strict_types=1);

final class LevelDbFixtureCorpus
{
    private const MAGIC = "BEDRIOX-LDB-CORPUS\x01";
    private const MAX_ENTRIES = 4096;
    private const MAX_FIELD_BYTES = 16_777_216;

    /**
     * @return list<array{key: string, value: string}>
     */
    public static function read(string $path): array
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException("Could not read fixture corpus: $path");
        }

        $offset = strlen(self::MAGIC);
        if (!str_starts_with($bytes, self::MAGIC) || strlen($bytes) < $offset + 4) {
            throw new RuntimeException('Fixture corpus has an invalid header.');
        }
        $count = self::uint32(substr($bytes, $offset, 4));
        $offset += 4;
        if ($count < 1 || $count > self::MAX_ENTRIES) {
            throw new RuntimeException('Fixture corpus entry count is outside the admitted range.');
        }

        $records = [];
        $previousKey = null;
        for ($index = 0; $index < $count; $index++) {
            if ($offset + 8 > strlen($bytes)) {
                throw new RuntimeException('Fixture corpus ended before an entry header.');
            }
            $keyLength = self::uint32(substr($bytes, $offset, 4));
            $valueLength = self::uint32(substr($bytes, $offset + 4, 4));
            $offset += 8;
            if ($keyLength < 1 || $keyLength > self::MAX_FIELD_BYTES || $valueLength > self::MAX_FIELD_BYTES) {
                throw new RuntimeException('Fixture corpus contains an invalid field length.');
            }
            if ($offset + $keyLength + $valueLength > strlen($bytes)) {
                throw new RuntimeException('Fixture corpus ended inside an entry.');
            }
            $key = substr($bytes, $offset, $keyLength);
            $offset += $keyLength;
            $value = substr($bytes, $offset, $valueLength);
            $offset += $valueLength;
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                throw new RuntimeException('Fixture corpus keys are not strictly bytewise ordered.');
            }
            $records[] = ['key' => $key, 'value' => $value];
            $previousKey = $key;
        }
        if ($offset !== strlen($bytes)) {
            throw new RuntimeException('Fixture corpus contains trailing bytes.');
        }

        return $records;
    }

    /**
     * @param list<array{key: string, value: string}> $records
     */
    public static function encode(array $records): string
    {
        if ($records === [] || count($records) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Fixture corpus requires a bounded non-empty record list.');
        }
        $bytes = self::MAGIC . pack('V', count($records));
        $previousKey = null;
        foreach ($records as $record) {
            $key = $record['key'];
            $value = $record['value'];
            if ($key === '' || strlen($key) > self::MAX_FIELD_BYTES || strlen($value) > self::MAX_FIELD_BYTES) {
                throw new InvalidArgumentException('Fixture corpus contains an invalid field length.');
            }
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                throw new InvalidArgumentException('Fixture corpus keys must be strictly bytewise ordered.');
            }
            $bytes .= pack('V2', strlen($key), strlen($value)) . $key . $value;
            $previousKey = $key;
        }

        return $bytes;
    }

    private static function uint32(string $bytes): int
    {
        $value = unpack('Vvalue', $bytes);
        if (!is_array($value)) {
            throw new RuntimeException('Could not decode a corpus integer.');
        }

        return $value['value'];
    }
}
