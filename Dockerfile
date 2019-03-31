FROM php:8.3-cli-alpine

RUN apk add --no-cache libxml2-dev curl-dev $PHPIZE_DEPS \
    && docker-php-ext-install curl dom \
    && apk del $PHPIZE_DEPS

WORKDIR /app

COPY . /app

RUN chmod +x /app/docker/start.sh \
    && mkdir -p /app/state

ENTRYPOINT ["/app/docker/start.sh"]

