# Render no longer offers a native PHP runtime, so PHP apps must run via Docker.
# This builds a minimal PHP 8 environment with PostgreSQL support and starts
# the same built-in PHP server used for local development.

FROM php:8.3-cli

# Install PostgreSQL client libraries and the pdo_pgsql / pgsql PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Render sends traffic to port 10000 by default
EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000", "-t", "/app"]
