FROM php:8.2-cli

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip zip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev libonig-dev curl python3 python3-pip python3-venv nodejs npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd bcmath intl mbstring pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN python3 -m pip install --break-system-packages -r requirements-pdf.txt \
    && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && npm ci \
    && npm run build \
    && chmod +x scripts/railway-start.sh

EXPOSE 8080

CMD ["sh", "-c", "./scripts/railway-start.sh"]
