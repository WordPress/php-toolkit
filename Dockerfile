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
        nodejs \
        npm \
        unzip \
        git \
    && sed -i 's/# en_US.UTF-8/en_US.UTF-8/' /etc/locale.gen \
    && locale-gen \
    && docker-php-ext-install zip pdo pdo_sqlite mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Playground CLI for WordPress integration smoke tests.
RUN npm install -g @wp-playground/cli@latest

WORKDIR /app

# Install dependencies first (cached layer for faster rebuilds)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --optimize-autoloader 2>/dev/null || true

# Copy the rest of the source
COPY . .

# Prewarm the WordPress Playground cache so e2e tests can boot offline in the
# no-network sandbox used by docker-compose.
RUN mkdir -p /root/.wordpress-playground \
	&& php -r '$config = json_decode(file_get_contents("plugins/wp-origin/blueprint-e2e.json"), true); if (! is_array($config) || ! isset($config["preferredVersions"]["wp"])) { fwrite(STDERR, "Missing WP Origin e2e WordPress version.\n"); exit(1); } $version = $config["preferredVersions"]["wp"]; $source = "https://downloads.wordpress.org/release/wordpress-" . $version . ".zip"; $target = "/root/.wordpress-playground/" . $version . ".zip"; if (! copy($source, $target)) { fwrite(STDERR, "Failed to prefetch " . $source . "\n"); exit(1); }'

# Re-run install in case the cached layer was stale
RUN composer install --no-interaction --no-progress --optimize-autoloader

CMD ["bash"]
