#!/usr/bin/env sh
set -eu

script_path=$0
script_dir=$(CDPATH= cd -- "$(dirname -- "$script_path")" && pwd -P)
# shellcheck source=common.sh
. "$script_dir/common.sh"

repository_root=$(runtime_resolve_root "$script_path") || runtime_die "unable to resolve repository root"

usage() {
	cat <<'EOF'
Usage: ./compile.sh --target <target> [--preflight-only | --download-only]

Native Unix targets:
  linux-x86_64
  linux-arm64
  macos-x86_64
  macos-arm64

Options:
  --preflight-only  Validate the manifest, host, and native toolchain only.
  --download-only   Also download and hash-verify every declared source.
  -h, --help        Show this help.
EOF
}

target=
mode=build
while [ "$#" -gt 0 ]; do
	case "$1" in
		--target)
			[ "$#" -ge 2 ] || runtime_die "--target requires a value"
			target=$2
			shift 2
			;;
		--target=*)
			target=${1#--target=}
			shift
			;;
		--preflight-only)
			[ "$mode" = build ] || runtime_die "choose only one execution mode"
			mode=preflight
			shift
			;;
		--download-only)
			[ "$mode" = build ] || runtime_die "choose only one execution mode"
			mode=download
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*) runtime_die "unknown option: $1" ;;
	esac
done

[ -n "$target" ] || runtime_die "--target is required"
case "$target" in
	linux-x86_64|linux-arm64|macos-x86_64|macos-arm64) ;;
	*) runtime_die "unsupported Unix target: $target" ;;
esac

"$script_dir/preflight.sh" "$repository_root" "$target"
[ "$mode" = preflight ] && exit 0

download_root=${BEDRIOX_RUNTIME_DOWNLOAD_DIR:-"$repository_root/downloads"}
source_root=${BEDRIOX_RUNTIME_SOURCE_DIR:-"$repository_root/sources"}
runtime_assert_safe_root "download" "$download_root"
runtime_assert_safe_root "source" "$source_root"
[ "$download_root" != "$repository_root" ] || runtime_die "download root must not be the repository root"
[ "$source_root" != "$repository_root" ] || runtime_die "source root must not be the repository root"
mkdir -p -- "$download_root"
"$script_dir/download-sources.sh" "$repository_root" "$target" "$download_root"
[ "$mode" = download ] && exit 0

build_parent=${BEDRIOX_RUNTIME_BUILD_DIR:-"$repository_root/build/unix/$target"}
runtime_assert_safe_root "build" "$build_parent"
[ "$build_parent" != "$repository_root" ] || runtime_die "build root must not be the repository root"
build_root="$build_parent/$(date -u '+%Y%m%dT%H%M%SZ')-$$"
mkdir -p -- "$build_root"
mkdir -p -- "$source_root"
"$script_dir/extract-sources.sh" "$repository_root" "$download_root" "$source_root"
"$script_dir/compile-native.sh" "$repository_root" "$target" "$source_root" "$download_root" "$build_root"
