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

# aws-sdk-php ships API definitions for every AWS service - around 50MB for
# 400-odd services this application never calls. Only Route53 is used, plus STS
# and SSO, which the credential chain needs for assumed roles, IRSA and SSO
# logins. Root-level manifests (endpoints, partitions) are left alone.
#
# The build verifies the result below, so if a future SDK needs something else
# the image fails to build rather than shipping broken.
RUN set -eux; \
    data=/app/vendor/aws/aws-sdk-php/src/data; \
    if [ -d "$data" ]; then \
        for dir in "$data"/*/; do \
            case "$(basename "$dir")" in \
                route53|sts|sso|sso-oidc) ;; \
                *) rm -rf "$dir" ;; \
            esac; \
        done; \
    fi

# --------------------------------------------------------------------- runtime
FROM php:8.3-cli-alpine AS runtime

# `pcntl` is what gives `ddns watch` a graceful shutdown when the container is
# stopped; without it SIGTERM kills the process mid-update.
#
# Alpine drops superseded package versions from its repositories quickly, so
# pinning them makes builds fail over time rather than making them reproducible.
# The base image tag is the pin that matters.
# hadolint ignore=DL3018
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

# Fails the build if the SDK trimming above removed something Route53 needs.
RUN php -r 'require "vendor/autoload.php"; \
    new Aws\Route53\Route53Client(["region" => "us-east-1", "version" => "2013-04-01", \
        "credentials" => ["key" => "x", "secret" => "y"]]);' \
    && php bin/ddns providers:list > /dev/null

# Numeric rather than `ddns`, so an orchestrator can verify this is not root
# without having to resolve a name from the image's passwd file.
USER 1000:1000

# Mount the real configuration here, or set DDNS_CONFIG to another path.
ENV DDNS_CONFIG=/config/ddns.yaml
VOLUME ["/config"]

EXPOSE 8080

# Shell form is required here: the `||` is what turns a curl failure into the
# unhealthy exit code Docker expects.
# hadolint ignore=DL3025
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/health || exit 1

# Serves HTTP by default. Override the command for CLI use, for example:
#   docker run --rm -v ./config/ddns.yaml:/config/ddns.yaml ddns bin/ddns update --all
#   docker run -d   -v ./config/ddns.yaml:/config/ddns.yaml ddns bin/ddns watch --all
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]

# ----------------------------------------------------------------- development
# Built by compose.dev.yaml. Adds the dev dependencies and the toolchain, and
# expects the source to be bind-mounted over the copies baked in above.
FROM runtime AS dev

# root, numerically, to install packages before dropping back to 1000.
USER 0:0

# hadolint ignore=DL3018
RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The production image caches opcode with timestamp validation switched off,
# which would make bind-mounted edits invisible until a restart.
RUN { \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'memory_limit=512M'; \
    } > "$PHP_INI_DIR/conf.d/zz-dev.ini"

COPY composer.json composer.lock* ./

# Composer warns loudly when run as root; it has to be here, because the source
# is bind-mounted over /app at runtime and vendor/ must already exist.
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer install --no-interaction --prefer-dist && composer clear-cache

COPY tests ./tests
COPY phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php ./

# The toolchain writes caches into the project root, so it has to be writable.
RUN chown -R ddns:ddns /app

USER 1000:1000

ENV COMPOSER_CACHE_DIR=/tmp/composer
