# syntax=docker/dockerfile:1
# NNTmux, Rust-native. One image holds nntmux-web, nntmux-worker and nntmux-ctl; each Compose
# service picks its binary and role with `command`. See docker/docker-compose.yml.

# ── Rust build. The registry and target directory are cache mounts, so dependencies are built
#    once. Cargo judges workspace sources by mtime, and the shared target cache may hold
#    artifacts from another checkout newer than these copied files, so every workspace source is
#    touched first: workspace crates always rebuild, dependencies stay cached. The toolchain tag
#    matches rust/rust-toolchain.toml exactly, so rustup never downloads another one.
FROM rust:1.98.0-bookworm AS build
WORKDIR /src/rust
COPY rust/Cargo.toml rust/Cargo.lock rust/rust-toolchain.toml ./
COPY rust/crates ./crates
COPY rust/migrations ./migrations
RUN --mount=type=cache,target=/usr/local/cargo/registry,sharing=locked \
    --mount=type=cache,target=/usr/local/cargo/git,sharing=locked \
    --mount=type=cache,target=/src/rust/target,sharing=locked \
    find Cargo.toml Cargo.lock crates migrations -type f -exec touch {} + \
    && cargo build --release --locked --bin nntmux-web --bin nntmux-worker --bin nntmux-ctl \
    && install -D -m 0755 -t /out target/release/nntmux-web target/release/nntmux-worker \
        target/release/nntmux-ctl

# ── Front end: Vite writes public/build and the manifest nntmux-web reads for its tags.
#    css/app.css scans the crates' templates (../../crates/*/templates), so the crates are
#    mounted at the same relative path for the build.
FROM node:22.21.1-bookworm-slim AS frontend
WORKDIR /src/rust/frontend
COPY rust/frontend/package.json rust/frontend/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY rust/frontend ./
RUN --mount=type=bind,source=rust/crates,target=/src/rust/crates npm run build

# ── Runtime: the binaries plus the tools post-processing runs (unrar, 7z, ffmpeg, mediainfo).
FROM debian:bookworm-slim AS runtime
ENV LANG=C.UTF-8
RUN sed -i 's/^Components: main$/Components: main non-free/' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl tzdata unrar p7zip-full ffmpeg mediainfo file \
    && rm -rf /var/lib/apt/lists/* \
    && install -d -o 33 -g 33 -m 0770 /app/storage /var/tmp/nntmux
COPY --from=build /out/ /usr/local/bin/
COPY --from=frontend /src/rust/frontend/public /app/public
WORKDIR /app
ENV NNTMUX_PUBLIC_DIR=/app/public \
    NNTMUX_STORAGE_DIR=/app/storage \
    NNTMUX_WEB_ADDR=0.0.0.0:8080 \
    NNTMUX_HEALTH_ADDR=0.0.0.0:9187 \
    NNTMUX_LOG_FORMAT=json
USER 33:33
EXPOSE 8080 9187
CMD ["nntmux-web"]
