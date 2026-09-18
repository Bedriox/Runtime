#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
# shellcheck source=common.sh
. "$script_dir/common.sh"

[ "$#" -eq 2 ] || runtime_die "preflight requires repository root and target"
repository_root=$1
target=$2

runtime_require_command php
[ -f "$repository_root/tools/manifest.php" ] || runtime_die "manifest reader is missing: tools/manifest.php"

php_version=$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;') || runtime_die "unable to query bootstrap PHP"
[ "$php_version" = 8.4 ] || runtime_die "repository tooling requires PHP 8.4; found PHP $php_version"

targets_json=$(php "$repository_root/tools/manifest.php" targets) || runtime_die "unable to read declared targets"
php -r '
$targets = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
exit(is_array($targets) && in_array($argv[2], $targets, true) ? 0 : 1);
' "$targets_json" "$target" || runtime_die "target is not declared by the immutable manifest: $target"

host_target=$(runtime_host_target)
[ "$host_target" = "$target" ] || runtime_die "target $target requires a native $target host; current host is $host_target"

for command_name in curl cmake make pkg-config autoconf automake libtool bison re2c tar; do
	runtime_require_command "$command_name"
done

case "$target" in
	linux-*)
		runtime_require_command cc
		runtime_require_command c++
		;;
	macos-*)
		runtime_require_command xcrun
		xcrun --find clang >/dev/null 2>&1 || runtime_die "Xcode command-line clang was not found"
		xcrun --find clang++ >/dev/null 2>&1 || runtime_die "Xcode command-line clang++ was not found"
		;;
esac

runtime_note "Preflight passed for native target $target."
