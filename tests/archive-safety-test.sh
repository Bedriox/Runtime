#!/usr/bin/env sh
set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
# shellcheck source=../scripts/unix/common.sh
. "$repository_root/scripts/unix/common.sh"

fixture_root=$(mktemp -d)
cleanup() {
	rm -rf -- "$fixture_root"
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$fixture_root/safe/root/bin" "$fixture_root/unsafe/root/bin"
printf 'fixture\n' >"$fixture_root/safe/root/bin/tool"
ln -s tool "$fixture_root/safe/root/bin/tool-alias"
tar -czf "$fixture_root/safe.tar.gz" -C "$fixture_root/safe" root
runtime_archive_links_are_safe "$fixture_root/safe.tar.gz" || {
	printf 'safe sibling symlink was rejected\n' >&2
	exit 1
}

printf 'fixture\n' >"$fixture_root/unsafe/root/bin/tool"
ln -s ../../outside "$fixture_root/unsafe/root/bin/escape"
tar -czf "$fixture_root/unsafe.tar.gz" -C "$fixture_root/unsafe" root
if runtime_archive_links_are_safe "$fixture_root/unsafe.tar.gz"; then
	printf 'escaping symlink was accepted\n' >&2
	exit 1
fi

printf 'Archive safety tests passed.\n'
