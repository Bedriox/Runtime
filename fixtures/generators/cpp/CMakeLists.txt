cmake_minimum_required(VERSION 3.22)
project(bedriox_leveldb_fixture_generator LANGUAGES C CXX)

if(NOT DEFINED LEVELDB_SOURCE OR NOT IS_DIRECTORY "${LEVELDB_SOURCE}")
  message(FATAL_ERROR "LEVELDB_SOURCE must identify the verified Google LevelDB source tree")
endif()
if(NOT DEFINED ZSTD_LIBRARY OR NOT EXISTS "${ZSTD_LIBRARY}")
  message(FATAL_ERROR "ZSTD_LIBRARY must identify the verified static zstd archive")
endif()
if(NOT DEFINED ZSTD_INCLUDE OR NOT EXISTS "${ZSTD_INCLUDE}/zstd.h")
  message(FATAL_ERROR "ZSTD_INCLUDE must identify the verified zstd headers")
endif()

add_library(zstd STATIC IMPORTED GLOBAL)
set_target_properties(zstd PROPERTIES
  IMPORTED_LOCATION "${ZSTD_LIBRARY}"
  INTERFACE_INCLUDE_DIRECTORIES "${ZSTD_INCLUDE}"
)
set(HAVE_ZSTD ON CACHE BOOL "" FORCE)
include_directories("${ZSTD_INCLUDE}")

set(LEVELDB_BUILD_TESTS OFF CACHE BOOL "" FORCE)
set(LEVELDB_BUILD_BENCHMARKS OFF CACHE BOOL "" FORCE)
set(LEVELDB_INSTALL OFF CACHE BOOL "" FORCE)
add_subdirectory("${LEVELDB_SOURCE}" leveldb EXCLUDE_FROM_ALL)

add_executable(leveldb_fixture_generator main.cc)
target_link_libraries(leveldb_fixture_generator PRIVATE leveldb)
target_compile_features(leveldb_fixture_generator PRIVATE cxx_std_17)
