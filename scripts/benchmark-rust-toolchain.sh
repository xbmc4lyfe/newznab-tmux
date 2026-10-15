#!/usr/bin/env bash

# Source this helper so Cargo in the current step inherits the selected store.
benchmark_rust_toolchain_setup() {
    : "${RUST_TOOLCHAIN:?RUST_TOOLCHAIN must name the pinned benchmark toolchain}"

    # Require binaries inside this pin: `run` alone can fall back to PATH.
    # Disable implicit installation while probing a read-only shared store.
    if RUSTUP_AUTO_INSTALL=0 rustup which --toolchain "$RUST_TOOLCHAIN" rustc >/dev/null &&
       RUSTUP_AUTO_INSTALL=0 rustup which --toolchain "$RUST_TOOLCHAIN" cargo >/dev/null &&
       rustup run "$RUST_TOOLCHAIN" rustc -V && rustup run "$RUST_TOOLCHAIN" cargo -V; then
        return 0
    fi

    # A missing pin must be installed into job-owned storage, never the shared
    # runner store. Keep CARGO_HOME unchanged; only Rustup needs this fallback.
    : "${RUNNER_TEMP:?RUNNER_TEMP must identify the job temporary directory}"
    : "${GITHUB_ENV:?GITHUB_ENV must identify the environment file for later steps}"
    local benchmark_rustup_home
    benchmark_rustup_home="$(mktemp -d "$RUNNER_TEMP/nntmux-benchmark-rustup.XXXXXX")" || return
    export RUSTUP_HOME="$benchmark_rustup_home"
    rustup toolchain install "$RUST_TOOLCHAIN" --profile minimal --no-self-update || return
    RUSTUP_AUTO_INSTALL=0 rustup which --toolchain "$RUST_TOOLCHAIN" rustc >/dev/null || return
    RUSTUP_AUTO_INSTALL=0 rustup which --toolchain "$RUST_TOOLCHAIN" cargo >/dev/null || return
    rustup run "$RUST_TOOLCHAIN" rustc -V || return
    rustup run "$RUST_TOOLCHAIN" cargo -V || return
    printf 'RUSTUP_HOME=%s\n' "$RUSTUP_HOME" >> "$GITHUB_ENV"
}

benchmark_rust_toolchain_setup
