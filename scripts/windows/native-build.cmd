@echo off
setlocal EnableExtensions DisableDelayedExpansion

if not defined BEDRIOX_NATIVE_PHP_SOURCE exit /b 11
if not defined BEDRIOX_NATIVE_PHP_DEPS_SOURCE exit /b 12
if not defined BEDRIOX_NATIVE_LEVELDB_SOURCE exit /b 13
if not defined BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE exit /b 14
if not defined BEDRIOX_NATIVE_WORK_ROOT exit /b 15
if not defined BEDRIOX_NATIVE_INSTALL_ROOT exit /b 16
if not defined BEDRIOX_NATIVE_CMAKE_EXE exit /b 17

set "PHP_SOURCE=%BEDRIOX_NATIVE_PHP_SOURCE%"
set "PHP_DEPS_SOURCE=%BEDRIOX_NATIVE_PHP_DEPS_SOURCE%"
set "LEVELDB_SOURCE=%BEDRIOX_NATIVE_LEVELDB_SOURCE%"
set "LEVELDB_EXTENSION_SOURCE=%BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE%"
set "WORK_ROOT=%BEDRIOX_NATIVE_WORK_ROOT%"
set "INSTALL_ROOT=%BEDRIOX_NATIVE_INSTALL_ROOT%"
set "CMAKE_EXE=%BEDRIOX_NATIVE_CMAKE_EXE%"

if not exist "%PHP_SOURCE%\buildconf.bat" exit /b 21
if not exist "%LEVELDB_SOURCE%\CMakeLists.txt" exit /b 22
if not exist "%LEVELDB_EXTENSION_SOURCE%\config.w32" exit /b 23
if not exist "%CMAKE_EXE%" exit /b 24

set "PHP_BUILD=%WORK_ROOT%\deps"
set "LEVELDB_BUILD=%WORK_ROOT%\leveldb"
if exist "%PHP_BUILD%" exit /b 25
if exist "%LEVELDB_BUILD%" exit /b 26
mkdir "%PHP_BUILD%" || exit /b 27
mkdir "%LEVELDB_BUILD%" || exit /b 28

xcopy "%PHP_DEPS_SOURCE%\*" "%PHP_BUILD%\" /E /I /Q /Y >nul || exit /b 29

"%CMAKE_EXE%" -S "%LEVELDB_SOURCE%" -B "%LEVELDB_BUILD%" -G "NMake Makefiles" -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="%PHP_BUILD%" -DCMAKE_PREFIX_PATH="%PHP_BUILD%" -DBUILD_SHARED_LIBS=OFF -DLEVELDB_BUILD_TESTS=OFF -DLEVELDB_BUILD_BENCHMARKS=OFF -DLEVELDB_INSTALL=ON -DLEVELDB_SNAPPY=OFF -DLEVELDB_ZLIB=ON -DLEVELDB_ZSTD=ON || exit /b 30
"%CMAKE_EXE%" --build "%LEVELDB_BUILD%" --config Release || exit /b 31
"%CMAKE_EXE%" --install "%LEVELDB_BUILD%" --config Release || exit /b 32

if exist "%PHP_SOURCE%\ext\leveldb" exit /b 33
mkdir "%PHP_SOURCE%\ext\leveldb" || exit /b 34
xcopy "%LEVELDB_EXTENSION_SOURCE%\*" "%PHP_SOURCE%\ext\leveldb\" /E /I /Q /Y >nul || exit /b 35

pushd "%PHP_SOURCE%" || exit /b 36
call buildconf.bat --force || (popd & exit /b 37)
call configure.bat --disable-all --enable-cli --enable-ctype --enable-dom --enable-fileinfo --enable-filter --enable-hash --enable-json --enable-mbstring --enable-opcache --enable-pdo --enable-phar --enable-session --enable-simplexml --enable-sockets --enable-tokenizer --enable-xml --enable-xmlreader --enable-xmlwriter --with-prefix="%INSTALL_ROOT%" --with-config-file-path="%INSTALL_ROOT%" --with-libxml --with-openssl --with-curl --with-zlib --with-zip --with-sodium --with-sqlite3 --with-pdo-sqlite --with-gmp --with-leveldb=shared,"%PHP_BUILD%" || (popd & exit /b 38)
nmake || (popd & exit /b 39)
nmake install || (popd & exit /b 40)
cl /? > "%WORK_ROOT%\compiler-identity.txt" 2>&1
link /? > "%WORK_ROOT%\linker-identity.txt" 2>&1
popd

exit /b 0
