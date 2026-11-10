# me -- one container for the public demo (2026-11-10, see docs/deploy.md):
# Laravel API + the built Vue app on the same address, plus the queue
# worker (AI replies) and the scheduler (hourly demo:prune).

# ---- 1. build the Vue app ------------------------------------------------
FROM node:22-alpine AS spa
WORKDIR /spa
COPY spa/package*.json ./
RUN npm ci
COPY spa/ ./
# empty API URL = "same address as the page"
ENV VITE_API_URL="" \
    VITE_DEMO_MODE=true
RUN npm run build

# ---- 2. PHP app ----------------------------------------------------------
FROM php:8.3-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
 && docker-php-ext-install pdo_pgsql pgsql zip bcmath opcache \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY api/ ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# the Vue build goes into public/: assets/ as-is, index.html as spa.html
# (public/index.php is Laravel's), served by routes/web.php's fallback
COPY --from=spa /spa/dist/ /tmp/spa/
RUN cp -r /tmp/spa/. public/ && mv public/index.html public/spa.html && rm -rf /tmp/spa

COPY deploy/start.sh /usr/local/bin/start
RUN sed -i 's/\r$//' /usr/local/bin/start && chmod +x /usr/local/bin/start

ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8080
CMD ["start"]
