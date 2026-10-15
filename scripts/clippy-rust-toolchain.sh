#!/usr/bin/env bash

# Source this helper so this step and later GitHub steps select the same store.
clippy_rust_tools_available() {
    local tool resolved
    for tool in rustc cargo cargo-clippy clippy-driver rustfmt cargo-fmt; do
        # `rustup run` can search PATH when a selected tool is absent. Require
        # the executable inside the exact pinned toolchain before probing it.
        resolved="$(rustup which --toolchain "$RUST_TOOLCHAIN" "$tool")" || return
        [[ -x "$resolved" ]] || return 1
        "$resolved" --version || return
    done
}

clippy_rust_toolchain_setup() {
    : "${RUST_TOOLCHAIN:?RUST_TOOLCHAIN must identify the pinned toolchain}"
    if clippy_rust_tools_available; then
        return 0
    fi

    # Never attempt an install or component update in the shared runner store.
    : "${RUNNER_TEMP:?RUNNER_TEMP must identify the job temporary directory}"
    : "${GITHUB_ENV:?GITHUB_ENV must identify the later-step environment file}"
    local clippy_rustup_home
    clippy_rustup_home="$(mktemp -d "$RUNNER_TEMP/nntmux-clippy-rustup.XXXXXX")" || return
    export RUSTUP_HOME="$clippy_rustup_home"
    rustup toolchain install "$RUST_TOOLCHAIN" --profile minimal \
        --component clippy --component rustfmt --no-self-update || return
    clippy_rust_tools_available || return
    printf 'RUSTUP_HOME=%s\n' "$RUSTUP_HOME" >> "$GITHUB_ENV"
}

clippy_rust_toolchain_setup
