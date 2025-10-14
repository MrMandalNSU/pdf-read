FROM php:8.2-cli

# Install system dependencies including Poppler
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install

CMD ["php", "artisan", "tinker"]