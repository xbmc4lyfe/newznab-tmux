#!/usr/bin/env bash
set -euo pipefail

: "${RUST_TOOLCHAIN:?RUST_TOOLCHAIN must name the pinned benchmark toolchain}"

# A preinstalled runner toolchain may be read-only: checking it must not sync
# channel metadata or write to RUSTUP_HOME, as `toolchain install` does.
if rustup run "$RUST_TOOLCHAIN" rustc -V && rustup run "$RUST_TOOLCHAIN" cargo -V; then
    exit 0
fi

rustup toolchain install "$RUST_TOOLCHAIN" --profile minimal
rustup run "$RUST_TOOLCHAIN" rustc -V
rustup run "$RUST_TOOLCHAIN" cargo -V
