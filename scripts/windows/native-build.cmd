@echo off
setlocal EnableExtensions DisableDelayedExpansion

if not defined BEDRIOX_NATIVE_PHP_SOURCE exit /b 11
if not defined BEDRIOX_NATIVE_PHP_DEPS_SOURCE exit /b 12
if not defined BEDRIOX_NATIVE_LEVELDB_SOURCE exit /b 13
if not defined BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE exit /b 14
if not defined BEDRIOX_NATIVE_WORK_ROOT exit /b 15
if not defined BEDRIOX_NATIVE_RUNTIME_STAGE exit /b 16
if not defined BEDRIOX_NATIVE_CMAKE_EXE exit /b 17
if not defined BEDRIOX_NATIVE_STAGE_SCRIPT exit /b 18
if not defined BEDRIOX_NATIVE_REPOSITORY_ROOT exit /b 19
if not defined BEDRIOX_NATIVE_INVOCATION_ROOT exit /b 20
if not defined BEDRIOX_NATIVE_ARCHITECTURE exit /b 62
if not defined VCToolsRedistDir exit /b 63
for %%I in ("%VCToolsRedistDir%\%BEDRIOX_NATIVE_ARCHITECTURE%\Microsoft.VC143.CRT") do set "VC_RUNTIME_DIR=%%~fI"
if not exist "%VC_RUNTIME_DIR%\vcruntime140.dll" exit /b 64

set "PHP_SOURCE=%BEDRIOX_NATIVE_PHP_SOURCE%"
set "PHP_DEPS_SOURCE=%BEDRIOX_NATIVE_PHP_DEPS_SOURCE%"
set "LEVELDB_SOURCE=%BEDRIOX_NATIVE_LEVELDB_SOURCE%"
set "LEVELDB_EXTENSION_SOURCE=%BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE%"
set "WORK_ROOT=%BEDRIOX_NATIVE_WORK_ROOT%"
set "RUNTIME_STAGE=%BEDRIOX_NATIVE_RUNTIME_STAGE%"
set "CMAKE_EXE=%BEDRIOX_NATIVE_CMAKE_EXE%"
set "STAGE_SCRIPT=%BEDRIOX_NATIVE_STAGE_SCRIPT%"

if not exist "%PHP_SOURCE%\buildconf.bat" exit /b 21
if not exist "%LEVELDB_SOURCE%\CMakeLists.txt" exit /b 22
if not exist "%LEVELDB_EXTENSION_SOURCE%\config.w32" exit /b 23
if not exist "%CMAKE_EXE%" exit /b 24
if not exist "%STAGE_SCRIPT%" exit /b 61

set "PHP_BUILD=%WORK_ROOT%\deps"
set "LEVELDB_BUILD=%WORK_ROOT%\leveldb"
if exist "%PHP_BUILD%" exit /b 25
if exist "%LEVELDB_BUILD%" exit /b 26
mkdir "%PHP_BUILD%" || exit /b 27
mkdir "%LEVELDB_BUILD%" || exit /b 28

xcopy "%PHP_DEPS_SOURCE%\*" "%PHP_BUILD%\" /E /I /Q /Y >nul || exit /b 29

set "ZLIB_HEADER=%PHP_BUILD%\include\zlib.h"
set "ZLIB_LIBRARY=%PHP_BUILD%\lib\zlib_a.lib"
set "ZSTD_HEADER=%PHP_BUILD%\include\zstd.h"
set "ZSTD_LIBRARY=%PHP_BUILD%\lib\libzstd_a.lib"
set "PHP_BUILD_CMAKE=%PHP_BUILD:\=/%"
set "ZLIB_LIBRARY_CMAKE=%ZLIB_LIBRARY:\=/%"
set "ZSTD_LIBRARY_CMAKE=%ZSTD_LIBRARY:\=/%"
if not exist "%ZLIB_HEADER%" exit /b 41
if not exist "%ZLIB_LIBRARY%" exit /b 42
if not exist "%ZSTD_HEADER%" exit /b 43
if not exist "%ZSTD_LIBRARY%" exit /b 44
if not exist "%PHP_BUILD%\include\curl\curl.h" exit /b 65
if not exist "%PHP_BUILD%\lib\libcurl_a.lib" exit /b 66
if not exist "%PHP_BUILD%\include\sodium.h" exit /b 67
if not exist "%PHP_BUILD%\lib\libsodium_a.lib" exit /b 68
if not exist "%PHP_BUILD%\include\libxml2\libxml\parser.h" exit /b 69
if not exist "%PHP_BUILD%\lib\libxml2_a_dll.lib" exit /b 70
if not exist "%PHP_BUILD%\include\iconv.h" exit /b 77
set "ICONV_LIBRARY="
for %%I in (libiconv_a.lib libiconv.lib iconv_a.lib iconv.lib) do if not defined ICONV_LIBRARY if exist "%PHP_BUILD%\lib\%%I" set "ICONV_LIBRARY=%PHP_BUILD%\lib\%%I"
if not defined ICONV_LIBRARY exit /b 78
if not exist "%PHP_BUILD%\include\sqlite3.h" exit /b 71
if not exist "%PHP_BUILD%\lib\libsqlite3_a.lib" exit /b 72
if not exist "%PHP_BUILD%\include\mpir\gmp.h" exit /b 73
if not exist "%PHP_BUILD%\lib\mpir_a.lib" exit /b 74
if not exist "%PHP_BUILD%\include\openssl\ssl.h" exit /b 75
if not exist "%PHP_BUILD%\lib\libssl.lib" exit /b 76

"%CMAKE_EXE%" -S "%LEVELDB_SOURCE%" -B "%LEVELDB_BUILD%" -G "NMake Makefiles" -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="%PHP_BUILD%" -DBUILD_SHARED_LIBS=ON -DLEVELDB_BUILD_TESTS=OFF -DLEVELDB_BUILD_BENCHMARKS=OFF -DLEVELDB_INSTALL=ON -DLEVELDB_SNAPPY=OFF -DLEVELDB_ZLIB=ON -DLEVELDB_ZSTD=ON "-DZLIB_INCLUDE_DIR:PATH=%PHP_BUILD_CMAKE%/include" "-DZLIB_LIBRARY:FILEPATH=%ZLIB_LIBRARY_CMAKE%" "-Dzstd_INCLUDE_DIRS:PATH=%PHP_BUILD_CMAKE%/include" "-Dzstd_LIBRARIES:FILEPATH=%ZSTD_LIBRARY_CMAKE%" || exit /b 30
set "LEVELDB_CACHE=%LEVELDB_BUILD%\CMakeCache.txt"
if not exist "%LEVELDB_CACHE%" exit /b 45
findstr /L /X /C:"ZLIB_INCLUDE_DIR:PATH=%PHP_BUILD_CMAKE%/include" "%LEVELDB_CACHE%" >nul || exit /b 46
findstr /L /X /C:"ZLIB_LIBRARY:FILEPATH=%ZLIB_LIBRARY_CMAKE%" "%LEVELDB_CACHE%" >nul || exit /b 47
findstr /L /X /C:"zstd_INCLUDE_DIRS:PATH=%PHP_BUILD_CMAKE%/include" "%LEVELDB_CACHE%" >nul || exit /b 48
findstr /L /X /C:"zstd_LIBRARIES:FILEPATH=%ZSTD_LIBRARY_CMAKE%" "%LEVELDB_CACHE%" >nul || exit /b 49
"%CMAKE_EXE%" --build "%LEVELDB_BUILD%" --config Release || exit /b 31
"%CMAKE_EXE%" --install "%LEVELDB_BUILD%" --config Release || exit /b 32
if not exist "%PHP_BUILD%\bin\leveldb.dll" exit /b 50
if not exist "%PHP_BUILD%\lib\leveldb.lib" exit /b 51
if not exist "%PHP_BUILD%\include\leveldb\db.h" exit /b 52

if exist "%PHP_SOURCE%\ext\leveldb" exit /b 33
mkdir "%PHP_SOURCE%\ext\leveldb" || exit /b 34
xcopy "%LEVELDB_EXTENSION_SOURCE%\*" "%PHP_SOURCE%\ext\leveldb\" /E /I /Q /Y >nul || exit /b 35

pushd "%PHP_SOURCE%" || exit /b 36
call buildconf.bat --force || (popd & exit /b 37)
call configure.bat --disable-all --enable-cli --enable-ctype --enable-dom --enable-fileinfo --enable-filter --enable-hash --enable-json --enable-mbstring --enable-opcache --enable-pdo --enable-phar --enable-session --enable-simplexml --enable-sockets --enable-tokenizer --enable-xml --enable-xmlreader --enable-xmlwriter --with-prefix=bedriox-runtime --with-config-file-path=bedriox-runtime --with-php-build="%PHP_BUILD%" --with-iconv --with-libxml --with-openssl --with-curl --with-zlib --with-zip --with-sodium --with-sqlite3 --with-pdo-sqlite --with-gmp --with-leveldb=shared,"%PHP_BUILD%"
if errorlevel 1 (popd & exit /b 38)
nmake || (popd & exit /b 39)
nmake snap || (popd & exit /b 40)
cl /? > "%WORK_ROOT%\compiler-identity.txt" 2>&1
link /? > "%WORK_ROOT%\linker-identity.txt" 2>&1
popd

powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%STAGE_SCRIPT%" -PhpSource "%PHP_SOURCE%" -RuntimeStage "%RUNTIME_STAGE%" -LevelDbDll "%PHP_BUILD%\bin\leveldb.dll" -VcRuntimeDirectory "%VC_RUNTIME_DIR%" -Architecture "%BEDRIOX_NATIVE_ARCHITECTURE%" -RepositoryRoot "%BEDRIOX_NATIVE_REPOSITORY_ROOT%" -InvocationRoot "%BEDRIOX_NATIVE_INVOCATION_ROOT%" -ReportRoot "%WORK_ROOT%" || exit /b 53
set "PHP_BIN=%RUNTIME_STAGE%\"
set "PHP_LEVELDB_DLL=%RUNTIME_STAGE%\ext\php_leveldb.dll"
if not exist "%PHP_BIN%php.exe" exit /b 54
if not exist "%PHP_LEVELDB_DLL%" exit /b 55
if not exist "%PHP_BIN%leveldb.dll" exit /b 56

dumpbin /nologo /dependents "%PHP_LEVELDB_DLL%" > "%WORK_ROOT%\php-leveldb-dependencies.txt" || exit /b 57
findstr /I /L /C:"leveldb.dll" "%WORK_ROOT%\php-leveldb-dependencies.txt" >nul || exit /b 58
dumpbin /nologo /dependents "%PHP_BIN%leveldb.dll" > "%WORK_ROOT%\leveldb-dependencies.txt" || exit /b 59
findstr /I /R "zlib.*\.dll zstd.*\.dll" "%WORK_ROOT%\leveldb-dependencies.txt" >nul && exit /b 60

exit /b 0
