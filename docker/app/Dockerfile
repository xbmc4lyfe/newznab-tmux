# syntax=docker/dockerfile:1
# Overlay for the self-hosted docker/ stack, built FROM the repository's production image
# (root Dockerfile, tagged nntmux/base:local by `make -C docker build`).
# Build context: repository root.
ARG BASE_IMAGE=nntmux/base:local

# Native yEnc decoder: nzb-decode behind RapidYenc's C ABI (docker/app/nzb-yenc-ffi),
# loaded by App\Services\Yenc\NativePayloadDecoder through FFI.
FROM rust:1.98-bookworm AS yenc
WORKDIR /src
COPY docker/app/nzb-yenc-ffi/ /src/
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    cargo test --release --locked \
    && cargo build --release --locked \
    && install -D -m 0644 target/release/libnzbyenc.so /out/libnzbyenc.so

FROM ${BASE_IMAGE}
USER root
RUN install-php-extensions ffi
COPY --from=yenc /out/libnzbyenc.so /usr/local/lib/nntmux/libnzbyenc.so
# - unrar (non-free): unrar-free cannot open RAR5 archives used by most current posts.
# - iproute2: NNTmux counts live NNTP sockets with `ss`.
# - file, htop, git: helpers used by post-processing, the tmux htop pane and the monitor header.
RUN sed -i 's/^Components: main$/Components: main non-free/' /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get purge -y unrar-free \
    && apt-get install -y --no-install-recommends unrar iproute2 file htop git \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory /app \
    && install -d -o www-data -g www-data /var/tmp/nntmux
COPY docker/app/php/zz-docker.ini /usr/local/etc/php/conf.d/zz-docker.ini
COPY docker/app/Caddyfile /etc/frankenphp/nntmux.Caddyfile
COPY --chmod=0755 docker/app/bin/indexer.sh /usr/local/bin/nntmux-indexer
USER www-data
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/nntmux.Caddyfile", "--adapter", "caddyfile"]
