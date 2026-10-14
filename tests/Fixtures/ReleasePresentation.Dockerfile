# Same native builder, PHP version and web runtime as the production image.
FROM rust:1.98-bookworm AS native
WORKDIR /src
COPY docker/app/nzb-yenc-ffi/ /src/
RUN cargo build --release --locked

FROM dunglas/frankenphp:1-php8.5-bookworm
RUN install-php-extensions ffi
COPY --from=native /src/target/release/libnzbyenc.so /usr/local/lib/nntmux/libnzbyenc.so
COPY docker/app/php/release-presentation.h docker/app/php/release-presentation-preload.php /usr/local/lib/nntmux/
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY tests/Fixtures/release-names-http.php /app/public/index.php
USER www-data
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
