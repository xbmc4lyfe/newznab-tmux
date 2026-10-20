# syntax=docker/dockerfile:1
FROM composer:2.8 AS composer
FROM node:22-bookworm AS node
FROM rust:1.98-bookworm AS rust-toolchain

# ── Base OS & PHP extensions (never invalidated by application or config files) ──
FROM dunglas/frankenphp:1-php8.5-bookworm AS base-os
ENV LANG=C.UTF-8
WORKDIR /app
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl unzip tmux par2 unrar-free p7zip-full lame ffmpeg mediainfo jpegoptim webp \
    optipng pngquant libavif-bin python3 time procps mariadb-client libmagic1 \
    && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions bcmath exif gd intl mbstring pdo_mysql pdo_sqlite sockets pcntl redis imagick zip ffi
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Self-hosted base image (tagged nntmux/base:local): installs non-free unrar and CLI
# tools before any repository COPY so OS/extension layers remain permanently cached.
FROM base-os AS base
RUN sed -i 's/^Components: main$/Components: main non-free/' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get purge -y unrar-free \
    && apt-get install -y --no-install-recommends unrar iproute2 file htop git \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory /app \
    && install -d -o www-data -g www-data /var/tmp/nntmux

# ── Isolated Rust FFI builder (cached unless docker/app/nzb-yenc-ffi or contracts change) ──
# Run the complete Rust suite in the PHP runtime it exercises. The benchmark
# binary's contract tests invoke PHP/FFI and inspect two reviewed source files.
FROM dunglas/frankenphp:1-php8.5-bookworm AS yenc
COPY --from=rust-toolchain /usr/local/cargo /usr/local/cargo
COPY --from=rust-toolchain /usr/local/rustup /usr/local/rustup
ENV CARGO_HOME=/usr/local/cargo RUSTUP_HOME=/usr/local/rustup PATH=/usr/local/cargo/bin:$PATH
RUN install-php-extensions ffi
WORKDIR /src/docker/app/nzb-yenc-ffi
COPY docker/app/nzb-yenc-ffi/ ./
COPY app/Services/Nzb/RustArticleFingerprintWorker.php /src/app/Services/Nzb/RustArticleFingerprintWorker.php
COPY tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php /src/tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php
# Only the registry is cached: Cargo trusts mtimes, and COPY preserves them, so a shared target
# cache could ship another checkout's artifacts.
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    cargo test --release --locked \
    && cargo build --release --locked \
    && install -D -m 0644 target/release/libnzbyenc.so /out/libnzbyenc.so \
    && install -D -m 0755 target/release/nntmux-article-fingerprint /out/nntmux-article-fingerprint \
    && install -D -m 0755 target/release/nntmux-verification-framer /out/nntmux-verification-framer

# ── Runtime stages with native FFI & PHP configuration ──
FROM base-os AS runtime
ENV LANG=C.UTF-8
WORKDIR /app
COPY --from=yenc /out/libnzbyenc.so /usr/local/lib/nntmux/libnzbyenc.so
COPY --from=yenc /out/nntmux-article-fingerprint /usr/local/bin/nntmux-article-fingerprint
COPY --from=yenc /out/nntmux-verification-framer /usr/local/bin/nntmux-verification-framer
COPY docker/app/php/release-presentation.h docker/app/php/release-presentation-preload.php /usr/local/lib/nntmux/
COPY docker/8.5/php.ini /usr/local/etc/php/conf.d/99-nntmux.ini
COPY docker/app/php/yenc-ffi.ini /usr/local/etc/php/indexer-conf.d/99-yenc-ffi.ini

FROM base AS selfhost-runtime
COPY --from=yenc /out/libnzbyenc.so /usr/local/lib/nntmux/libnzbyenc.so
COPY --from=yenc /out/nntmux-article-fingerprint /usr/local/bin/nntmux-article-fingerprint
COPY --from=yenc /out/nntmux-verification-framer /usr/local/bin/nntmux-verification-framer
COPY docker/app/php/release-presentation.h docker/app/php/release-presentation-preload.php /usr/local/lib/nntmux/
COPY docker/8.5/php.ini /usr/local/etc/php/conf.d/99-nntmux.ini
COPY docker/app/php/zz-docker.ini /usr/local/etc/php/conf.d/zz-docker.ini

# ── Isolated Composer vendor builder (cached unless composer.json / composer.lock change) ──
FROM base-os AS vendor
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock /app/
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

# ── Isolated Frontend Vite builder (cached unless frontend assets / package*.json change) ──
FROM node AS frontend
WORKDIR /app
COPY package*.json /app/
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY vite.config.js /app/
COPY app/Support/AdminReleaseNames.php /app/app/Support/AdminReleaseNames.php
COPY resources/css /app/resources/css
COPY resources/js /app/resources/js
COPY resources/forum /app/resources/forum
COPY resources/views /app/resources/views
COPY public /app/public
RUN ./node_modules/.bin/vite build

# ── PHP application assembly (explicit layers ordered from stable to volatile; no Node/Vite) ──
FROM vendor AS build
COPY artisan server.php /app/
COPY bootstrap /app/bootstrap
COPY deploy/cloud /app/deploy/cloud
COPY lang /app/lang
COPY public /app/public
COPY database /app/database
COPY config /app/config
COPY routes /app/routes
COPY resources/forum /app/resources/forum
COPY resources/views /app/resources/views
COPY app /app/app
RUN composer dump-autoload --no-dev --no-scripts --optimize \
    && mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && touch /tmp/build.sqlite \
    && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/build.sqlite CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array php artisan package:discover --ansi \
    && rm -rf /tmp/build.sqlite bootstrap/cache/*.php \
    && mkdir -p /app/_install /app/storage/covers /app/storage/app/public \
    && ln -s ../storage/covers /app/public/covers \
    && ln -s ../storage/app/public /app/public/storage \
    && chmod +x /app/deploy/cloud/*.sh

# ── Shared application core & role-specific self-hosted targets ──
FROM selfhost-runtime AS app-core
COPY --from=build --chown=www-data:www-data /app /app
USER www-data
ENTRYPOINT ["/app/deploy/cloud/entrypoint.sh"]

FROM app-core AS selfhosted-web
USER root
COPY docker/app/Caddyfile /etc/frankenphp/nntmux.Caddyfile
COPY --from=frontend --chown=www-data:www-data /app/public/build /app/public/build
USER www-data
EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/nntmux.Caddyfile", "--adapter", "caddyfile"]

FROM app-core AS selfhosted-horizon
CMD ["php", "artisan", "horizon"]

FROM app-core AS selfhosted-scheduler
COPY --chown=www-data:www-data docker/app/bin/scheduler.py /app/docker/app/bin/scheduler.py
RUN python3 -c 'import ast; ast.parse(open("/app/docker/app/bin/scheduler.py").read())'
CMD ["python3", "/app/docker/app/bin/scheduler.py"]

FROM app-core AS selfhosted-indexer
USER root
COPY docker/app/php/yenc-ffi.ini /usr/local/etc/php/indexer-conf.d/99-yenc-ffi.ini
COPY --chmod=0755 docker/app/bin/indexer.sh /usr/local/bin/nntmux-indexer
USER www-data
CMD ["/usr/local/bin/nntmux-indexer"]

FROM selfhosted-web AS selfhosted
USER root
COPY --chown=www-data:www-data docker/app/bin/scheduler.py /app/docker/app/bin/scheduler.py
RUN python3 -c 'import ast; ast.parse(open("/app/docker/app/bin/scheduler.py").read())'
COPY docker/app/php/yenc-ffi.ini /usr/local/etc/php/indexer-conf.d/99-yenc-ffi.ini
COPY --chmod=0755 docker/app/bin/indexer.sh /usr/local/bin/nntmux-indexer
USER www-data
EXPOSE 80
ENTRYPOINT ["/app/deploy/cloud/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/nntmux.Caddyfile", "--adapter", "caddyfile"]

FROM runtime AS production
ENV LANG=C.UTF-8
COPY --from=build --chown=www-data:www-data /app /app
COPY --from=frontend --chown=www-data:www-data /app/public/build /app/public/build
USER www-data
EXPOSE 80
ENTRYPOINT ["/app/deploy/cloud/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/app/deploy/cloud/Frankenphp.Caddyfile", "--adapter", "caddyfile"]
