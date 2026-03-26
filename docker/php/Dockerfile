FROM php:8.5-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) \
    zip \
    mbstring \
    xml

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG UID=1000
ARG GID=1000

RUN groupadd -g "${GID}" appuser && useradd -u "${UID}" -g appuser -m appuser

WORKDIR /var/www/html
RUN chown "${UID}:${GID}" /var/www/html

USER appuser

CMD ["/bin/bash"]
