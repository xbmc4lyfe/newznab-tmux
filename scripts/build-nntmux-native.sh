#!/usr/bin/env bash
set -euo pipefail

# Build the existing Rust decoder, article identity worker, and pipeline FFI library for native installs.
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
crate="$root/docker/app/nzb-yenc-ffi"
pipeline_crate="$root/rust/pipeline-ffi"
output="$root/storage/app/nntmux-native"
case "$(uname -s)" in
    Linux) suffix=so ;;
    Darwin) suffix=dylib ;;
    *) echo 'NNTmux native builds require Linux or macOS.' >&2; exit 1 ;;
esac
cargo test --release --locked --manifest-path "$crate/Cargo.toml"
cargo build --release --locked --manifest-path "$crate/Cargo.toml"
cargo test --release --locked --manifest-path "$pipeline_crate/Cargo.toml" --target-dir "$pipeline_crate/target"
cargo build --release --locked --manifest-path "$pipeline_crate/Cargo.toml" --target-dir "$pipeline_crate/target"
mkdir -p "$output"
install -m 0644 "$crate/target/release/libnzbyenc.$suffix" "$output/libnzbyenc.$suffix"
install -m 0644 "$pipeline_crate/target/release/libnntmux_pipeline.$suffix" "$output/libnntmux_pipeline.$suffix"
install -m 0755 "$crate/target/release/nntmux-article-fingerprint" "$output/nntmux-article-fingerprint"
