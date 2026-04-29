FROM php:8.3-cli-alpine3.22

ARG APP_VERSION=0.5.1

LABEL org.opencontainers.image.title="InterNetX DynDNS" \
      org.opencontainers.image.description="Container-only InterNetX XML API DynDNS worker" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.vendor="worryboy"

WORKDIR /app

RUN set -eux; \
    apk add --no-cache --virtual .phpize-deps \
        $PHPIZE_DEPS \
        curl-dev \
        libxml2-dev; \
    docker-php-ext-install -j"$(nproc)" curl dom; \
    apk add --no-cache \
        ca-certificates \
        libcurl \
        libxml2; \
    apk del --no-network .phpize-deps

COPY . /app

RUN chmod +x /app/docker/start.sh \
    && mkdir -p /app/state

ENTRYPOINT ["/app/docker/start.sh"]
