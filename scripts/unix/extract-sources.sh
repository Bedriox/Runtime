#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
# shellcheck source=common.sh
. "$script_dir/common.sh"

[ "$#" -eq 3 ] || runtime_die "source extraction requires repository root, download root, and source root"
repository_root=$1
download_root=$2
source_root=$3
runtime_assert_safe_root "download" "$download_root"
runtime_assert_safe_root "source" "$source_root"

active_temporary=
cleanup_extraction() {
	[ -z "$active_temporary" ] || rm -rf -- "$active_temporary"
}
trap cleanup_extraction EXIT
trap 'cleanup_extraction; exit 129' HUP
trap 'cleanup_extraction; exit 130' INT
trap 'cleanup_extraction; exit 143' TERM

source_ids='php zlib gmp libiconv curl openssl libzip sqlite zstd leveldb php-leveldb libdatachannel libjuice plog usrsctp ext-webrtc libsodium libxml2 oniguruma'
for source_id in $source_ids; do
	url=$(php "$repository_root/tools/manifest.php" source "$source_id" url)
	digest=$(php "$repository_root/tools/manifest.php" source "$source_id" sha256)
	archive_type=$(php "$repository_root/tools/manifest.php" source "$source_id" archiveType)
	case "$archive_type" in
		tar.gz|tar.xz|tar.bz2) ;;
		*) runtime_die "unsupported Unix source archive type for $source_id: $archive_type" ;;
	esac
	file_name=${url%%\?*}
	file_name=${file_name##*/}
	archive="$download_root/$source_id-$file_name"
	[ -f "$archive" ] || runtime_die "verified source archive is missing: $source_id"

	destination="$source_root/$source_id-$digest"
	marker="$destination/.bedriox-extracted"
	if [ -f "$marker" ] && [ "$(cat "$marker")" = "$digest" ]; then
		runtime_note "Using extracted source: $source_id"
		continue
	fi
	[ ! -e "$destination" ] || runtime_die "unverified source directory already exists: $destination"

	runtime_note "Inspecting and extracting source: $source_id"
	listing=$(tar -tf "$archive") || runtime_die "unable to inspect archive: $source_id"
	printf '%s\n' "$listing" | awk '
		BEGIN { bad = 0 }
		/^\// { bad = 1 }
		/(^|\/)\.\.($|\/)/ { bad = 1 }
		END { exit bad }
	' || runtime_die "archive contains an escaping path: $source_id"
	runtime_archive_links_are_safe "$archive" || runtime_die "archive contains an unsafe link: $source_id"

	temporary="$source_root/.extract-$source_id-$$"
	runtime_assert_safe_root "temporary extraction" "$temporary"
	active_temporary=$temporary
	mkdir -p -- "$temporary"
	if ! tar -xf "$archive" -C "$temporary"; then
		rm -rf -- "$temporary"
		runtime_die "unable to extract source: $source_id"
	fi
	set -- "$temporary"/*
	[ "$#" -eq 1 ] && [ -d "$1" ] || {
		rm -rf -- "$temporary"
		runtime_die "source archive must contain exactly one root directory: $source_id"
	}
	mv -- "$1" "$destination"
	rmdir -- "$temporary"
	active_temporary=
	printf '%s\n' "$digest" >"$marker"
done

runtime_note "All source archives were safely extracted."
