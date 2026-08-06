# syntax=docker/dockerfile:1

# ---------------------------------------------------------------- dependencies
FROM composer:2 AS vendor

WORKDIR /app

# Copy only the manifests first so the dependency layer is cached until they
# actually change.
COPY composer.json composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
    && composer clear-cache

# --------------------------------------------------------------------- runtime
FROM php:8.3-cli-alpine AS runtime

# `pcntl` is what gives `ddns watch` a graceful shutdown when the container is
# stopped; without it SIGTERM kills the process mid-update.
RUN docker-php-ext-install pcntl \
    && apk add --no-cache curl \
    && rm -rf /var/cache/apk/*

# Production INI: opcache on, no expose_php, sane limits.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'expose_php=0'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'memory_limit=128M'; \
    } > "$PHP_INI_DIR/conf.d/ddns.ini"

# Never run the server as root: it only needs to read its own code and config.
RUN addgroup -g 1000 -S ddns && adduser -u 1000 -S ddns -G ddns

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY --chown=ddns:ddns bin ./bin
COPY --chown=ddns:ddns config ./config
COPY --chown=ddns:ddns public ./public
COPY --chown=ddns:ddns src ./src
COPY --chown=ddns:ddns composer.json ./

RUN chmod +x bin/ddns

USER ddns

# Mount the real configuration here, or set DDNS_CONFIG to another path.
ENV DDNS_CONFIG=/config/ddns.yaml
VOLUME ["/config"]

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/health || exit 1

# Serves HTTP by default. Override the command for CLI use, for example:
#   docker run --rm -v ./ddns.yaml:/config/ddns.yaml ddns bin/ddns update --all
#   docker run -d   -v ./ddns.yaml:/config/ddns.yaml ddns bin/ddns watch --all
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
