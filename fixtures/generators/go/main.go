package main

import (
	"bytes"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"os"

	"github.com/df-mc/goleveldb/leveldb"
	"github.com/df-mc/goleveldb/leveldb/filter"
	"github.com/df-mc/goleveldb/leveldb/opt"
	"github.com/df-mc/goleveldb/leveldb/util"
)

const corpusMagic = "BEDRIOX-LDB-CORPUS\x01"

type record struct {
	key   []byte
	value []byte
}

func main() {
	if len(os.Args) != 4 {
		fatal("usage: go-fixture-generator <none|raw-deflate> <corpus> <output>")
	}
	compression := opt.NoCompression
	switch os.Args[1] {
	case "none":
	case "raw-deflate":
		compression = opt.FlateCompression
	default:
		fatal("unsupported compression: %s", os.Args[1])
	}
	records, err := readCorpus(os.Args[2])
	if err != nil {
		fatal("read corpus: %v", err)
	}
	if _, err := os.Stat(os.Args[3]); !errors.Is(err, os.ErrNotExist) {
		fatal("output path must not exist: %s", os.Args[3])
	}

	options := &opt.Options{
		BlockCacheCapacity:     -1,
		BlockSize:              512,
		Compression:            compression,
		ErrorIfExist:           true,
		Filter:                 filter.NewBloomFilter(10),
		OpenFilesCacheCapacity: -1,
		WriteBuffer:            64 * 1024,
	}
	database, err := leveldb.OpenFile(os.Args[3], options)
	if err != nil {
		fatal("open output: %v", err)
	}
	for _, entry := range records {
		if err := database.Put(entry.key, entry.value, nil); err != nil {
			fatal("write output: %v", err)
		}
	}
	if err := database.CompactRange(util.Range{}); err != nil {
		fatal("compact output: %v", err)
	}
	if err := database.Close(); err != nil {
		fatal("close output: %v", err)
	}

	reader, err := leveldb.OpenFile(os.Args[3], &opt.Options{ReadOnly: true, Strict: opt.StrictAll})
	if err != nil {
		fatal("reopen output: %v", err)
	}
	iterator := reader.NewIterator(nil, nil)
	index := 0
	for iterator.Next() {
		if index >= len(records) || !bytes.Equal(iterator.Key(), records[index].key) || !bytes.Equal(iterator.Value(), records[index].value) {
			fatal("reopened output differs at entry %d", index)
		}
		index++
	}
	if err := iterator.Error(); err != nil {
		fatal("iterate output: %v", err)
	}
	iterator.Release()
	if index != len(records) {
		fatal("reopened output contains %d entries, expected %d", index, len(records))
	}
	if value, err := reader.Get([]byte("missing-fixture-key"), nil); err != leveldb.ErrNotFound || value != nil {
		fatal("missing-key lookup did not return ErrNotFound")
	}
	if err := reader.Close(); err != nil {
		fatal("close reopened output: %v", err)
	}
}

func readCorpus(path string) ([]record, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	header := make([]byte, len(corpusMagic))
	if _, err := io.ReadFull(file, header); err != nil || string(header) != corpusMagic {
		return nil, errors.New("invalid corpus header")
	}
	var count uint32
	if err := binary.Read(file, binary.LittleEndian, &count); err != nil || count == 0 || count > 4096 {
		return nil, errors.New("invalid corpus count")
	}
	records := make([]record, 0, count)
	var previous []byte
	for index := uint32(0); index < count; index++ {
		var lengths [2]uint32
		if err := binary.Read(file, binary.LittleEndian, &lengths); err != nil || lengths[0] == 0 || lengths[0] > 16*1024*1024 || lengths[1] > 16*1024*1024 {
			return nil, errors.New("invalid corpus entry lengths")
		}
		entry := record{key: make([]byte, lengths[0]), value: make([]byte, lengths[1])}
		if _, err := io.ReadFull(file, entry.key); err != nil {
			return nil, err
		}
		if _, err := io.ReadFull(file, entry.value); err != nil {
			return nil, err
		}
		if previous != nil && bytes.Compare(previous, entry.key) >= 0 {
			return nil, errors.New("corpus keys are not ordered")
		}
		previous = entry.key
		records = append(records, entry)
	}
	var trailing [1]byte
	if read, err := file.Read(trailing[:]); err != io.EOF || read != 0 {
		return nil, errors.New("corpus contains trailing bytes")
	}
	return records, nil
}

func fatal(format string, values ...any) {
	fmt.Fprintf(os.Stderr, format+"\n", values...)
	os.Exit(1)
}
