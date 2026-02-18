FROM php:8.2-cli
RUN apt-get update && apt-get install -y git curl zip unzip libpng-dev libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev sqlite3 libsqlite3-dev ca-certificates gnupg && docker-php-ext-install pdo pdo_mysql pdo_sqlite mbstring xml curl zip && apt-get clean
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt-get install -y nodejs
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --optimize-autoloader --no-dev --no-interaction
RUN composer require doctrine/dbal --no-interaction
RUN npm install --legacy-peer-deps && npm run build
RUN cp .env.example .env && php artisan key:generate
RUN mkdir -p database && touch database/database.sqlite
RUN chmod -R 775 storage bootstrap/cache database
RUN php artisan migrate --force
EXPOSE 8000
CMD php artisan serve --host=0.0.0.0 --port=$PORT
