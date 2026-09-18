#include <cstdint>
#include <cstdlib>
#include <fstream>
#include <iostream>
#include <memory>
#include <string>
#include <vector>

#include "leveldb/db.h"
#include "leveldb/filter_policy.h"
#include "leveldb/options.h"

namespace {

constexpr char kCorpusMagic[] = "BEDRIOX-LDB-CORPUS\x01";

struct Record {
  std::string key;
  std::string value;
};

[[noreturn]] void Fail(const std::string& message) {
  std::cerr << message << '\n';
  std::exit(1);
}

uint32_t ReadUint32(std::istream& input) {
  unsigned char bytes[4];
  input.read(reinterpret_cast<char*>(bytes), sizeof(bytes));
  if (!input) Fail("corpus ended inside an integer");
  return static_cast<uint32_t>(bytes[0]) |
         (static_cast<uint32_t>(bytes[1]) << 8) |
         (static_cast<uint32_t>(bytes[2]) << 16) |
         (static_cast<uint32_t>(bytes[3]) << 24);
}

std::vector<Record> ReadCorpus(const char* path) {
  std::ifstream input(path, std::ios::binary);
  if (!input) Fail("could not open corpus");
  std::string header(sizeof(kCorpusMagic) - 1, '\0');
  input.read(header.data(), static_cast<std::streamsize>(header.size()));
  if (!input || header != kCorpusMagic) Fail("invalid corpus header");
  const uint32_t count = ReadUint32(input);
  if (count == 0 || count > 4096) Fail("invalid corpus count");
  std::vector<Record> records;
  records.reserve(count);
  for (uint32_t index = 0; index < count; ++index) {
    const uint32_t key_size = ReadUint32(input);
    const uint32_t value_size = ReadUint32(input);
    if (key_size == 0 || key_size > 16 * 1024 * 1024 || value_size > 16 * 1024 * 1024) {
      Fail("invalid corpus entry lengths");
    }
    Record record{std::string(key_size, '\0'), std::string(value_size, '\0')};
    input.read(record.key.data(), static_cast<std::streamsize>(record.key.size()));
    input.read(record.value.data(), static_cast<std::streamsize>(record.value.size()));
    if (!input) Fail("corpus ended inside an entry");
    if (!records.empty() && records.back().key >= record.key) Fail("corpus keys are not ordered");
    records.push_back(std::move(record));
  }
  if (input.peek() != std::char_traits<char>::eof()) Fail("corpus contains trailing bytes");
  return records;
}

void RequireOk(const leveldb::Status& status, const char* operation) {
  if (!status.ok()) Fail(std::string(operation) + ": " + status.ToString());
}

}  // namespace

int main(int argc, char** argv) {
  if (argc != 3) Fail("usage: leveldb_fixture_generator <corpus> <output>");
  const std::vector<Record> records = ReadCorpus(argv[1]);

  leveldb::Options options;
  options.create_if_missing = true;
  options.error_if_exists = true;
  options.block_size = 512;
  options.write_buffer_size = 64 * 1024;
  options.compression = leveldb::kZstdCompression;
  const std::unique_ptr<const leveldb::FilterPolicy> filter_policy(
      leveldb::NewBloomFilterPolicy(10));
  options.filter_policy = filter_policy.get();
  leveldb::DB* raw_database = nullptr;
  RequireOk(leveldb::DB::Open(options, argv[2], &raw_database), "open output");
  std::unique_ptr<leveldb::DB> database(raw_database);
  for (const Record& record : records) {
    RequireOk(database->Put(leveldb::WriteOptions(), record.key, record.value), "write output");
  }
  database->CompactRange(nullptr, nullptr);
  database.reset();

  options.create_if_missing = false;
  options.error_if_exists = false;
  RequireOk(leveldb::DB::Open(options, argv[2], &raw_database), "reopen output");
  database.reset(raw_database);
  std::unique_ptr<leveldb::Iterator> iterator(database->NewIterator(leveldb::ReadOptions()));
  std::size_t index = 0;
  for (iterator->SeekToFirst(); iterator->Valid(); iterator->Next()) {
    if (index >= records.size() || iterator->key().ToString() != records[index].key ||
        iterator->value().ToString() != records[index].value) {
      Fail("reopened output differs at entry " + std::to_string(index));
    }
    ++index;
  }
  RequireOk(iterator->status(), "iterate output");
  if (index != records.size()) Fail("reopened output has the wrong entry count");
  std::string missing;
  const leveldb::Status missing_status =
      database->Get(leveldb::ReadOptions(), "missing-fixture-key", &missing);
  if (!missing_status.IsNotFound()) Fail("missing-key lookup did not return NotFound");
  database.reset();
  return 0;
}
