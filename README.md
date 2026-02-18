# Daily Grow — Интеграция с Яндекс Картами

Веб-приложение для сбора и отображения отзывов из Яндекс Карт.

## Функционал

- Авторизация (логин / пароль)
- Страница настроек — вставка ссылки на карточку Яндекс Карт
- Вывод всех отзывов из карточки
- Рейтинг компании и общее количество отзывов
- Постраничная навигация и сортировка по новизне

## Требования

- PHP >= 8.1
- Composer 2
- Node.js >= 18
- MySQL >= 5.7 (или PostgreSQL >= 13, или SQLite)

## Установка (локально)

```bash
# 1. Клонировать репозиторий
git clone https://github.com/YOUR_USERNAME/yandex-reviews.git
cd yandex-reviews

# 2. Установить PHP-зависимости
composer install

# 3. Установить JS-зависимости
npm install

# 4. Настроить окружение
cp .env.example .env
php artisan key:generate

# 5. Настроить базу данных в .env
#    DB_CONNECTION=mysql
#    DB_DATABASE=yandex_reviews
#    DB_USERNAME=root
#    DB_PASSWORD=your_password

# 6. Выполнить миграции
php artisan migrate

# 7. Собрать фронтенд
npm run build

# 8. Запустить сервер
php artisan serve
```

Приложение будет доступно по адресу http://localhost:8000

## Использование

1. Откройте http://localhost:8000 и зарегистрируйтесь
2. Перейдите в раздел **Настройка**
3. Вставьте ссылку на карточку Яндекс Карт, например:
   `https://yandex.ru/maps/org/samoye_populyarnoye_kafe/1010501395/reviews/`
4. Нажмите **Сохранить** — отзывы загрузятся автоматически
5. Перейдите в раздел **Отзывы** для просмотра

## Деплой на VDS (Ubuntu 22.04+)

```bash
# Установка зависимостей сервера
sudo apt update && sudo apt install -y \
  php8.2 php8.2-fpm php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-mysql php8.2-zip \
  composer nodejs npm nginx mysql-server

# Клонирование и настройка
cd /var/www
git clone https://github.com/YOUR_USERNAME/yandex-reviews.git
cd yandex-reviews

composer install --optimize-autoloader --no-dev
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# Отредактировать .env — указать DB, APP_URL и т.д.

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Права
sudo chown -R www-data:www-data storage bootstrap/cache

# Nginx
sudo cp nginx.conf.example /etc/nginx/sites-available/yandex-reviews
# Отредактировать server_name и пути
sudo ln -s /etc/nginx/sites-available/yandex-reviews /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## Стек технологий

| Слой       | Технология                      |
|------------|----------------------------------|
| Backend    | Laravel 10, PHP 8.1+            |
| Frontend   | Vue 3, Vue Router, Pinia        |
| Стили      | Tailwind CSS                     |
| Сборка     | Vite                             |
| Авторизация| Laravel Sanctum (SPA cookies)   |
| Парсинг    | Guzzle HTTP + Symfony DomCrawler |

## Структура проекта

```
├── app/
│   ├── Http/Controllers/      # AuthController, SettingsController, ReviewController
│   ├── Models/                # User, YandexSource, Review
│   └── Services/              # YandexReviewsService (парсинг отзывов)
├── database/migrations/       # Таблицы: users, yandex_sources, reviews
├── resources/js/
│   ├── pages/                 # LoginPage, RegisterPage, SettingsPage, ReviewsPage
│   ├── layouts/               # AppLayout (сайдбар + контент)
│   ├── router/                # Vue Router
│   └── store/                 # Pinia (auth store)
├── routes/
│   ├── api.php                # API-маршруты
│   └── web.php                # SPA catch-all
└── nginx.conf.example         # Конфигурация Nginx
```
