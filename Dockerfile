FROM php:8.3-cli-alpine

ARG APP_VERSION=0.1.0

LABEL org.opencontainers.image.title="InterNetX DynDNS" \
      org.opencontainers.image.description="Container-only InterNetX XML API DynDNS worker" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.vendor="worryboy"

RUN apk add --no-cache libxml2-dev curl-dev $PHPIZE_DEPS \
    && docker-php-ext-install curl dom \
    && apk del $PHPIZE_DEPS

WORKDIR /app

COPY . /app

RUN chmod +x /app/docker/start.sh \
    && mkdir -p /app/state

ENTRYPOINT ["/app/docker/start.sh"]
