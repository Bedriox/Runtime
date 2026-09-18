#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
# shellcheck source=common.sh
. "$script_dir/common.sh"

[ "$#" -eq 3 ] || runtime_die "source download requires repository root, target, and download root"
repository_root=$1
target=$2
download_root=$3

runtime_require_command curl
runtime_require_command php
runtime_assert_safe_root "download" "$download_root"
mkdir -p -- "$download_root"

case "$(uname -s)" in
	Darwin)
		runtime_require_command shasum
		hash_file() { shasum -a 256 -- "$1" | awk '{ print $1 }'; }
		;;
	*)
		runtime_require_command sha256sum
		hash_file() { sha256sum -- "$1" | awk '{ print $1 }'; }
		;;
esac

source_ids='php zlib gmp libiconv curl openssl libzip sqlite zstd leveldb php-leveldb ca-bundle libsodium libxml2 oniguruma'
for source_id in $source_ids; do
	url=$(php "$repository_root/tools/manifest.php" source "$source_id" url) || runtime_die "missing URL for source: $source_id"
	expected=$(php "$repository_root/tools/manifest.php" source "$source_id" sha256) || runtime_die "missing SHA-256 for source: $source_id"
	expected_bytes=$(php "$repository_root/tools/manifest.php" source "$source_id" bytes) || runtime_die "missing byte count for source: $source_id"
	source_targets=$(php "$repository_root/tools/manifest.php" source "$source_id" targets) || runtime_die "missing targets for source: $source_id"
	php -r '
$targets = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
exit(is_array($targets) && in_array($argv[2], $targets, true) ? 0 : 1);
' "$source_targets" "$target" || runtime_die "source $source_id is not declared for target $target"
	case "$url" in
		https://*) ;;
		*) runtime_die "source URL must use HTTPS: $source_id" ;;
	esac
	case "$expected" in
		*[!0-9a-fA-F]*|'') runtime_die "invalid SHA-256 for source: $source_id" ;;
	esac
	[ "${#expected}" -eq 64 ] || runtime_die "invalid SHA-256 length for source: $source_id"
	case "$expected_bytes" in
		*[!0-9]*|'') runtime_die "invalid byte count for source: $source_id" ;;
	esac

	url_path=${url%%\?*}
	file_name=${url_path##*/}
	[ -n "$file_name" ] || runtime_die "source URL has no archive name: $source_id"
	destination="$download_root/$source_id-$file_name"
	partial="$destination.partial.$$"

	if [ -f "$destination" ]; then
		actual=$(hash_file "$destination")
		actual_bytes=$(wc -c <"$destination" | tr -d '[:space:]')
		if [ "$actual" = "$expected" ] && [ "$actual_bytes" = "$expected_bytes" ]; then
			runtime_note "Using verified cached source: $source_id"
			continue
		fi
		runtime_die "cached source hash mismatch: $destination"
	fi

	rm -f -- "$partial"
	runtime_note "Downloading pinned source: $source_id"
	if ! curl --fail --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
		--retry 3 --retry-all-errors --retry-delay 2 \
		--connect-timeout 30 --max-filesize "$expected_bytes" --output "$partial" -- "$url"; then
		rm -f -- "$partial"
		runtime_die "download failed: $source_id"
	fi
	actual=$(hash_file "$partial")
	actual_bytes=$(wc -c <"$partial" | tr -d '[:space:]')
	if [ "$actual" != "$expected" ] || [ "$actual_bytes" != "$expected_bytes" ]; then
		rm -f -- "$partial"
		runtime_die "downloaded source hash mismatch: $source_id"
	fi
	mv -- "$partial" "$destination"
done

runtime_note "All immutable sources for $target are present and hash-verified."
