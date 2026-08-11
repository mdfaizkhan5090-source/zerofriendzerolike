FROM php:8.2-cli

WORKDIR /app

# Install system dependencies required for pdo_sqlite
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Install required extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Copy all project files
COPY . .

# Expose port (Render sets $PORT)
EXPOSE 10000

# Start PHP built-in server
CMD php -S 0.0.0.0:$PORT
