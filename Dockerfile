# syntax=docker/dockerfile:1
FROM composer:2.8 AS composer
FROM node:22-bookworm AS node
FROM rust:1.98-bookworm AS rust-toolchain

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
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    cargo test --release --locked \
    && cargo build --release --locked \
    && install -D -m 0644 target/release/libnzbyenc.so /out/libnzbyenc.so \
    && install -D -m 0755 target/release/nntmux-article-fingerprint /out/nntmux-article-fingerprint

# System packages, PHP extensions and native assets never depend on application source.
FROM dunglas/frankenphp:1-php8.5-bookworm AS runtime
ENV LANG=C.UTF-8
WORKDIR /app
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl unzip tmux par2 unrar-free p7zip-full lame ffmpeg mediainfo jpegoptim webp \
    optipng pngquant libavif-bin python3 time procps mariadb-client libmagic1 \
    && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions bcmath exif gd intl mbstring pdo_mysql pdo_sqlite sockets pcntl redis imagick zip ffi
COPY --from=yenc /out/libnzbyenc.so /usr/local/lib/nntmux/libnzbyenc.so
COPY --from=yenc /out/nntmux-article-fingerprint /usr/local/bin/nntmux-article-fingerprint
COPY docker/app/php/release-presentation.h docker/app/php/release-presentation-preload.php /usr/local/lib/nntmux/
COPY docker/8.5/php.ini /usr/local/etc/php/conf.d/99-nntmux.ini
COPY docker/app/php/yenc-ffi.ini /usr/local/etc/php/indexer-conf.d/99-yenc-ffi.ini
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Self-hosted tools are installed before application COPY, so code-only builds reuse them.
FROM runtime AS selfhost-runtime
RUN sed -i 's/^Components: main$/Components: main non-free/' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get purge -y unrar-free \
    && apt-get install -y --no-install-recommends unrar iproute2 file htop git \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory /app \
    && install -d -o www-data -g www-data /var/tmp/nntmux
COPY docker/app/php/zz-docker.ini /usr/local/etc/php/conf.d/zz-docker.ini
COPY docker/app/Caddyfile /etc/frankenphp/nntmux.Caddyfile
COPY --chmod=0755 docker/app/bin/indexer.sh /usr/local/bin/nntmux-indexer

FROM runtime AS build
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/ /usr/local/
RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock /app/
RUN --mount=type=cache,target=/root/.composer/cache composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY package*.json /app/
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY . /app
RUN composer dump-autoload --no-dev --no-scripts --optimize \
    && mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && touch /tmp/build.sqlite \
    && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/build.sqlite CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array php artisan package:discover --ansi \
    && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/build.sqlite CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array npm run build \
    && python3 -c 'import ast; ast.parse(open("docker/app/bin/scheduler.py").read())' \
    && rm -rf node_modules /tmp/build.sqlite bootstrap/cache/*.php

RUN mkdir -p /app/_install /app/storage/covers /app/storage/app/public \
    && ln -s ../storage/covers /app/public/covers \
    && ln -s ../storage/app/public /app/public/storage \
    && chmod +x /app/deploy/cloud/*.sh

FROM selfhost-runtime AS selfhosted
COPY --from=build --chown=www-data:www-data /app /app
USER www-data
EXPOSE 80
ENTRYPOINT ["/app/deploy/cloud/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/nntmux.Caddyfile", "--adapter", "caddyfile"]

FROM runtime AS production
ENV LANG=C.UTF-8
COPY --from=build --chown=www-data:www-data /app /app
USER www-data
EXPOSE 80
ENTRYPOINT ["/app/deploy/cloud/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/app/deploy/cloud/Frankenphp.Caddyfile", "--adapter", "caddyfile"]
