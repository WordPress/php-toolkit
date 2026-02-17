FROM php:8.1-cli

# Match CI locale settings
ENV LC_ALL=en_US.UTF-8
ENV LANG=en_US.UTF-8

# Install system dependencies for PHP extensions and locale
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libsqlite3-dev \
        libonig-dev \
        locales \
        unzip \
        git \
    && sed -i 's/# en_US.UTF-8/en_US.UTF-8/' /etc/locale.gen \
    && locale-gen \
    && docker-php-ext-install zip pdo pdo_sqlite mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (cached layer for faster rebuilds)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --optimize-autoloader 2>/dev/null || true

# Copy the rest of the source
COPY . .

# Re-run install in case the cached layer was stale
RUN composer install --no-interaction --no-progress --optimize-autoloader

CMD ["bash"]
