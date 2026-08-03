# avtovokzal_new_platform

Новая платформа АИС автовокзалов. Стек: **PHP 8.5 / Laravel 13 / Nuxt / PostgreSQL 17**
в Docker Compose. Все монтирования — bind mounts (без именованных томов).

План миграции — см. [PLAN.md](PLAN.md).

## Документация
- [docs/LEGACY.md](docs/LEGACY.md) — разбор легаси (архитектура, поток покупки билета, процедуры Firebird, подводные камни).

## Требования
- Docker + Docker Compose v2.

## Быстрый старт
```bash
cp .env.example .env          # при необходимости поправить порты/пароль/UID-GID
docker compose build app      # собрать php-образ (pdo_pgsql + pdo_firebird)
docker compose up -d          # поднять весь стек
docker compose exec app php artisan migrate   # миграции в Postgres
```

Первый старт `frontend` ставит зависимости (`npm install`) — Nuxt поднимется через ~1 мин.

## Сервисы и порты
| Сервис    | Что                          | URL / порт            |
|-----------|------------------------------|-----------------------|
| web       | nginx → Laravel (`backend`)  | http://localhost:8080 |
| frontend  | Nuxt dev-сервер (`frontend`) | http://localhost:3000 |
| db        | PostgreSQL 17                | localhost:5432        |
| app       | php-fpm 8.5 (Laravel)        | внутренний (9000)     |
| firebird  | Firebird 2.5 (наши + BUSTER) | localhost:3050        |
| legacy    | PHP 5.6 — АИС `ais/`          | http://localhost:8090 |
| legacy_site | PHP 5.6 — сайт `avtovokzal57/` | http://localhost:8091 |

### Legacy-окружение (сверка при переносе)
Боевой код из `./htdocs` (распакованный бэкап) работает в контейнере `legacy`
как есть — **без правок кода**. Класс `db_work` написан под ODBC; чтобы не тащить
Firebird-ODBC под Linux, глобально подключён `docker/legacy/odbc-shim.php`
(`auto_prepend_file`), который эмулирует `odbc_*` через нативный Firebird и
перенаправляет ВСЕ подключения на локальный контейнер `firebird` (по `db_name` →
алиас `BUSTER`/`site`/`ais`). Боевой `.htaccess` (Deny + cp1251) в контейнере
игнорируется (`AllowOverride None`, UTF-8).

Примеры сверки:
- `http://localhost:8090/agents.php` → отчёт АИС по Красной Заре (stan=5722), база BUSTER.
- `http://localhost:8090/back.php` → возвраты (база ais).
- `http://localhost:8091/` → публичный сайт avtovokzal57 (база site).

**Кодировка:** shim уважает `charSet` из ODBC-DSN (как боевой драйвер). Конфиги с
`charSet=UTF-8` (напр. `ais/config.php` для back.php) получают данные уже в UTF-8;
конфиги без charSet (напр. `admin/inc/config.php` для agents.php) — сырой WIN1251,
а страница сама делает `iconv`. Базы Firebird — WIN1251, конвертацию выполняет сам Firebird.

### Локальные Firebird-базы (`./databases`, восстановлены из бэкапов)
| Алиас  | Файл        | Что                                   |
|--------|-------------|---------------------------------------|
| BUSTER | buster.fdb  | Красная Заря (вендорная, 222 табл.)   |
| ais    | ais.fdb     | наша (12 табл.: ККМ, сбербанк, выручка)|
| site   | site.fdb    | наша (5 табл.: users/orders/bilets/…) |

Восстановление бэкапа: `gbak -c -n -o -user SYSDBA -password masterkey <файл.fbk> /firebird/data/<имя>.fdb`
(флаг `-n` обходит NOT NULL в вендорных бэкапах).

Порты, пароль БД и UID/GID задаются в `.env`.

## Базы данных
- **PostgreSQL** (`db`) — наши данные (`site`, `ais` после переноса). Данные в
  `./data/postgres` (bind mount).
- **Firebird 2.5** (вендорные базы продаж) — внешние, по сети. В php-образе есть
  `pdo_firebird`; подключения возьмём из `ais/admin/inc/config.php` (`$AWT`).
  Доступные PDO-драйверы: `pgsql`, `firebird`, `sqlite`.

## Частые команды
```bash
docker compose ps                       # статус
docker compose logs -f app|web|frontend # логи
docker compose exec app php artisan ... # artisan
docker compose exec app composer ...    # composer
docker compose exec db psql -U avtovokzal -d avtovokzal   # psql
docker compose down                     # остановить (данные Postgres сохранятся в ./data)
```

## Структура
```
docker-compose.yml        # сервисы (dev)
docker/php/Dockerfile     # php 8.5 + расширения (pdo_pgsql, pdo_firebird, intl, zip, bcmath, pcntl)
docker/php/php.ini        # настройки PHP (dev)
docker/nginx/default.conf # nginx -> php-fpm
backend/                  # Laravel 13  (bind mount)
frontend/                 # Nuxt         (bind mount)
data/postgres/            # данные Postgres (bind mount, не в git)
```

## Прод (позже)
Отдельный `docker-compose.prod.yml` (оверрайд): собранный Nuxt вместо dev-сервера,
opcache без `validate_timestamps`, реальные секреты, без публикации порта БД.
Сейчас реализовано только dev-окружение — логика приложений ещё не переписывается.
