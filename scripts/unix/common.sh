#!/usr/bin/env sh

runtime_die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

runtime_note() {
	printf '%s\n' "$*"
}

runtime_resolve_root() {
	common_dir=$(CDPATH= cd -- "$(dirname -- "$1")" && pwd -P) || return 1
	CDPATH= cd -- "$common_dir/../.." && pwd -P
}

runtime_host_target() {
	os_name=$(uname -s) || runtime_die "unable to determine the host operating system"
	architecture=$(uname -m) || runtime_die "unable to determine the host architecture"

	case "$os_name" in
		Linux) platform=linux ;;
		Darwin) platform=macos ;;
		*) runtime_die "unsupported Unix host: $os_name" ;;
	esac

	case "$architecture" in
		x86_64|amd64) architecture=x86_64 ;;
		aarch64|arm64) architecture=arm64 ;;
		*) runtime_die "unsupported host architecture: $architecture" ;;
	esac

	printf '%s-%s\n' "$platform" "$architecture"
}

runtime_require_command() {
	command -v "$1" >/dev/null 2>&1 || runtime_die "required command not found: $1"
}

runtime_archive_links_are_safe() {
	archive=$1

	# Hard links are unnecessary for the pinned inputs. Symlinks are accepted only
	# when they name a sibling entry, which cannot traverse outside the directory
	# containing the link during extraction.
	tar -tvf "$archive" | awk '
		$1 ~ /^h/ { exit 1 }
		$1 ~ /^l/ {
			separator = " -> "
			position = index($0, separator)
			if (position == 0) {
				exit 1
			}
			target = substr($0, position + length(separator))
			if (target !~ /^[A-Za-z0-9][A-Za-z0-9._+-]*$/) {
				exit 1
			}
		}
	'
}

runtime_assert_safe_root() {
	root_name=$1
	root_path=$2

	[ -n "$root_path" ] || runtime_die "$root_name root must not be empty"
	[ "$root_path" != "/" ] || runtime_die "$root_name root must not be the filesystem root"
	[ "$root_path" != "." ] || runtime_die "$root_name root must not be the current directory"
	[ -z "${HOME:-}" ] || [ "$root_path" != "$HOME" ] || runtime_die "$root_name root must not be the home directory"
	case "$root_path" in
		/*) ;;
		*) runtime_die "$root_name root must be an absolute path: $root_path" ;;
	esac
	case "$root_path" in
		*"/../"*|*/..|../*|..)
			runtime_die "$root_name root must not contain unresolved parent segments: $root_path"
			;;
	esac
}
