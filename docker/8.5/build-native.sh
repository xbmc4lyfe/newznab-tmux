#!/usr/bin/env bash
set -euo pipefail

# Sail mounts the application after building its docker/8.5 image context.
project="${1:-/var/www/html}"
prefix="${2:-/usr/local}"
if [ $# -gt 2 ]; then
    echo 'Usage: build-native.sh [mounted-project-directory [install-prefix]]' >&2
    exit 2
fi
crate="$project/docker/app/nzb-yenc-ffi"
if [ ! -f "$crate/Cargo.toml" ] || [ ! -f "$crate/Cargo.lock" ]; then
    echo "Cannot build Sail native helpers: mounted application crate is missing at $crate." >&2
    exit 1
fi

lib_dir="$prefix/lib/nntmux"
bin_dir="$prefix/bin"
target="$lib_dir/build"
mkdir -p "$lib_dir" "$bin_dir"
if ! cargo build --release --locked --manifest-path "$crate/Cargo.toml" --target-dir "$target"; then
    echo 'Cannot start Sail: native article fingerprint build failed.' >&2
    exit 1
fi
library="$target/release/libnzbyenc.so"
worker="$target/release/nntmux-article-fingerprint"
if [ ! -f "$library" ] || [ ! -f "$worker" ]; then
    echo 'Cannot start Sail: native build did not produce its library and worker.' >&2
    exit 1
fi

library_tmp=''
worker_tmp=''
trap 'rm -f -- "$library_tmp" "$worker_tmp"' EXIT
library_tmp="$(mktemp "$lib_dir/.libnzbyenc.XXXXXX")"
worker_tmp="$(mktemp "$bin_dir/.nntmux-article-fingerprint.XXXXXX")"
install -m 0644 "$library" "$library_tmp"
install -m 0755 "$worker" "$worker_tmp"
mv -f -- "$library_tmp" "$lib_dir/libnzbyenc.so"
mv -f -- "$worker_tmp" "$bin_dir/nntmux-article-fingerprint"
