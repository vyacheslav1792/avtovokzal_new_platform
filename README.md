# avtovokzal_new_platform

Новая платформа АИС автовокзалов. Стек: **PHP 8.5 / Laravel 13 / Nuxt / PostgreSQL 17**
в Docker Compose. Все монтирования — bind mounts (без именованных томов).

План миграции — см. [PLAN.md](PLAN.md).

## Документация
- [docs/LEGACY.md](docs/LEGACY.md) — разбор легаси (архитектура, поток покупки билета, процедуры Firebird, подводные камни).
- [docs/buster-procedures/](docs/buster-procedures/) — исходники ключевых вендорных процедур BUSTER
  (продажа, резерв, возврат, валидация данных пассажира `KS_CONTROL_DATA_PAS`).
- `docs/captured-purchase-payloads.log` — эталонные запросы/ответы legacy API продаж, снятые
  при сквозной тестовой покупке (для сверки при переносе).

## Требования
- Docker + Docker Compose v2.

## Быстрый старт
```bash
cp .env.example .env          # при необходимости поправить порты/пароль/UID-GID
docker compose build app      # собрать php-образ (pdo_pgsql + pdo_firebird)
docker compose up -d          # поднять весь стек
# базы sales, kkm и ais создаются один раз вручную:
docker compose exec db psql -U avtovokzal -d avtovokzal -c "CREATE DATABASE sales OWNER avtovokzal"
docker compose exec db psql -U avtovokzal -d avtovokzal -c "CREATE DATABASE kkm OWNER avtovokzal"
docker compose exec db psql -U avtovokzal -d avtovokzal -c "CREATE DATABASE ais OWNER avtovokzal"
docker compose exec app php artisan migrate        # миграции backend
docker compose exec sales_app php artisan migrate  # миграции API продаж
docker compose exec kkm_app php artisan migrate    # миграции KKM API
docker compose exec ais_app php artisan migrate    # миграции AIS API
```

Первый старт `frontend` ставит зависимости (`npm install`) — Nuxt поднимется через ~1 мин.

## Сервисы и порты
| Сервис    | Что                          | URL / порт            |
|-----------|------------------------------|-----------------------|
| web       | nginx → высокоуровневое API (`backend`) | http://localhost:8080 |
| sales_web | nginx → **API продаж** (`sales-api`) | http://localhost:8085 |
| kkm_web   | nginx → **KKM API** (`kkm-api`) | http://localhost:8087 |
| ais_web   | nginx → **AIS API** (`ais-api`) | http://localhost:8086 |
| frontend  | Nuxt + Nuxt UI (`frontend`)  | http://localhost:3000 |
| ais_spa   | SPA отчётов АИС (Vue 3 + Vite, dev) | http://localhost:3001 |
| db        | PostgreSQL 17 (`avtovokzal` + `sales` + `kkm` + `ais`) | localhost:5432 |
| app       | php-fpm 8.5 (`backend`)      | внутренний (9000)     |
| sales_app | php-fpm 8.5 (`sales-api`)    | внутренний (9000)     |
| kkm_app   | php-fpm 8.5 (`kkm-api`), имя контейнера `avtovokzal-kkm-app` | внутренний (9000) |
| scheduler | ofelia — задания по расписанию (job-exec через docker.sock) | — |
| gotenberg | Chromium за HTTP API — рендер PDF билетов | внутренний (3000)     |
| firebird  | Firebird 2.5 (наши + BUSTER) | localhost:3050        |
| legacy    | PHP 5.6 — АИС `ais/`          | http://localhost:8090 |
| legacy_site | PHP 5.6 — сайт `avtovokzal57/` | http://localhost:8091 |

Вне docker: **KkmServer** (kkmserver.ru) установлен на хост-машине — systemd
`kkmserver.service`, http://127.0.0.1:5893, виртуальная ККТ NumDevice=1 (эмулятор).
Из контейнеров доступен как `host.docker.internal:5893` (extra_hosts host-gateway).

### Сервисы новой платформы
- **`sales-api/`** (:8085) — API продаж: работа с базами автостанций (Firebird BUSTER),
  рейсы/места/резерв/продажа/возврат. Автономен, без оплат. Своя БД Postgres `sales`:
  журнал запросов/ответов (`api_requests`), резервы, продажи, возвраты. Агенты —
  Bearer-токен + IP whitelist (`config/agents.php`).
- **`backend/`** (:8080) — API avtovokzal57: заказы, серверный пересчёт цен, оплата
  Сбербанк (SDK `voronkovich/sberbank-acquiring-client`), подтверждение и оформление,
  возврат по коду (`refunds/preview|confirm`), данные печатных форм
  (`orders/{id}/print`) и билеты одним PDF (`orders/{id}/pdf` — Gotenberg рендерит
  страницу `/print/{id}` фронта, как legacy `pdf.php` рендерил `print.php`
  через wkhtmltopdf), постановка чека ККМ и письма в database-очередь
  (`receipts`/`emails`; чеки обрабатываются через KKM API, воркер писем пока не
  запускается). Валидация данных пассажира — по правилам станции
  (`PassengerDocument`, снята с `KS_CONTROL_DATA_PAS`), ФИО фильтруется, Ё→Е.
  Таймзона приложения — Europe/Moscow (времена рейсов BUSTER — wall-clock +3).
  **Личный кабинет** (`auth/*`, `cabinet/*`): регистрация с паролем и телефоном,
  вход по Bearer-токену Sanctum, сброс пароля ссылкой из письма, приглашение
  завести кабинет после первой покупки, список заказов и возврат билета
  (правила — общие с возвратом по коду). Чёрный список адресов (legacy `$CHS`) —
  таблица `blocked_emails` + команда `emails:block`; закрывает покупку,
  регистрацию, восстановление пароля и приглашение.
- **`kkm-api/`** (:8087) — KKM API: прослойка к KkmServer для фискальных чеков.
  Своя БД Postgres `kkm`: журнал `api_requests` + чеки `receipts`. Идемпотентность
  двух уровней: `external_id` клиента (повтор не пробивает второй чек) и `IdCommand`
  внутри KkmServer (перед повторной отправкой — `GetRezult`). Просроченная смена
  (>24 ч, 54-ФЗ) закрывается автоматически Z-отчётом с повтором чека; плюс ночной
  Z-отчёт в 23:59 МСК (`kkm:close-shift` по расписанию ofelia).
- **`ais-api/`** (:8086) — API отчётов АИС: доступ к базам автостанций (Firebird,
  **общий конфиг `shared/stations.php`** — в legacy $AWT дублировался трижды;
  в списке только реально развёрнутые станции, все поля legacy сохранены),
  к БД сайта (Postgres `avtovokzal`, connection `site`) и своей БД `ais`.
  Отчёты — классы (`config/reports.php` + `app/Reports/*`), контракт
  `reports`/`meta`/`run` совместим с legacy apiv2. Перенесён отчёт
  «Агентские билеты» (вывод сверен с legacy 1:1).
- **`frontend/`** (:3000) — Nuxt 4 + Nuxt UI 4: поиск, рейсы, пассажиры, оплата,
  страница успеха, возврат по коду (`/backbycode`), печатные формы билетов
  (`/print/{id}`), личный кабинет (`/lk`, `/lk/profile`, `/login`, `/register`,
  `/forgot`, `/reset`), расписание автобусов (`/raspisanie-avtobusov`).
  Ходит только в `backend` (:8080); SSR — внутри
  docker-сети (`http://web`). Единственная серверная ручка самого Nuxt —
  `/api/auth/session`: хранит Bearer-токен кабинета в httpOnly cookie
  (Safari/ITP стирает JS-cookie и localStorage через неделю) и отдаёт его
  порталу; срок — полгода бездействия, продлевается при каждом чтении.
- **`ais-spa/`** (:3001, dev) — SPA отчётов АИС (Vue 3 + Vite), перенос из
  устаревшего репозитория `avtovokzal_ais_spa`; ходит в ais-api (`/api/v1/reports*`).

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
docker compose exec app php artisan ... # artisan backend
docker compose exec sales_app php artisan ...             # artisan API продаж
docker compose exec kkm_app php artisan ...               # artisan KKM API
docker compose exec kkm_app php artisan kkm:close-shift   # Z-отчёт вручную
docker compose exec app php artisan queue:work --queue=receipts --stop-when-empty  # прогнать чеки
docker compose exec app php artisan queue:work --queue=emails --stop-when-empty    # прогнать письма (в т.ч. приглашения в ЛК)
docker compose exec app php artisan emails:block                       # чёрный список e-mail: показать
docker compose exec app php artisan emails:block a@b.ru --reason=спам  # заблокировать адрес
docker compose exec app php artisan emails:block a@b.ru --remove       # разблокировать
docker compose exec app php artisan auth:prune-tokens                  # чистка токенов ЛК без активности
docker compose exec app php artisan schedule:sync                      # обновить регулярное расписание станций
docker compose exec app composer ...    # composer
docker compose exec db psql -U avtovokzal -d avtovokzal   # psql (backend)
docker compose exec db psql -U avtovokzal -d sales        # psql (API продаж, журнал)
docker compose exec db psql -U avtovokzal -d kkm          # psql (KKM API, чеки)
docker compose logs scheduler           # запуски заданий ofelia
docker compose down                     # остановить (данные Postgres сохранятся в ./data)
```

## Структура
```
docker-compose.yml        # сервисы (dev)
docker/php/Dockerfile     # php 8.5 + расширения (pdo_pgsql, pdo_firebird, intl, zip, bcmath, pcntl)
docker/php/php.ini        # настройки PHP (dev)
docker/nginx/default.conf # nginx -> backend (app:9000)
docker/nginx/sales.conf   # nginx -> sales-api (sales_app:9000)
docker/nginx/kkm.conf     # nginx -> kkm-api (kkm_app:9000)
docker/nginx/ais.conf     # nginx -> ais-api (ais_app:9000)
docker/ofelia/config.ini  # задания планировщика (ночной Z-отчёт 23:59 МСК)
shared/stations.php       # ЕДИНЫЙ конфиг станций (sales-api + ais-api): подключения к базам
                          # + бухгалтерские поля buh/buh_st/buh_ksr, stationkey, inlist.
                          # Только реально развёрнутые базы (сейчас Красная Заря 5722);
                          # боевые реквизиты остальных сохранены закомментированными
backend/                  # Laravel 13 — высокоуровневое API avtovokzal57 (bind mount)
sales-api/                # Laravel 13 — API продаж (bind mount)
kkm-api/                  # Laravel 13 — KKM API, прослойка к KkmServer (bind mount)
ais-api/                  # Laravel 13 — API отчётов АИС (bind mount)
frontend/                 # Nuxt 4 + Nuxt UI (bind mount)
ais-spa/                  # Vue 3 + Vite — SPA отчётов АИС (bind mount)
docs/buster-procedures/   # исходники ключевых процедур BUSTER (справочно)
data/postgres/            # данные Postgres (bind mount, не в git)
```

## Прод (позже)
Отдельный `docker-compose.prod.yml` (оверрайд): собранный Nuxt вместо dev-сервера,
opcache без `validate_timestamps`, реальные секреты, без публикации порта БД.

## Статус переноса
Перенесено на новый стек и проверено сквозными прогонами (2026-08-03):
- **покупка**: фронт → backend → sales-api → Firebird → тестовый Сбербанк → билеты в BUSTER;
- **возврат по коду** (`/backbycode`): предпросмотр удержаний → аннулирование в BUSTER →
  возврат платежа → чек возврата;
- **печатные формы билетов** (`/print/{id}`) с багажными квитанциями и обезличиванием;
- **фискализация** через KKM API на виртуальной ККТ KkmServer (чеки прихода и возврата,
  идемпотентность, автозакрытие смены);
- **личный кабинет** (`/lk`): регистрация (в том числе по приглашению из письма),
  вход, сброс и смена пароля, история заказов, печать/PDF, возврат билета;
- **регулярное расписание** (`/raspisanie-avtobusov`): данные в JSON из БД
  (`station_schedules`, обновление `schedule:sync`), сверено с legacy 1:1;
  выбор пункта прибытия ведёт к рейсам маршрута;
- **рейсы маршрута** (`/trips`): поиск билетов и legacy-расписание до остановки
  на одной странице — переключатель `?all=1` добавляет рейсы без интернет-продажи
  со статусами, без `?date` показываются ближайшие рейсы (legacy `blij=1`);
- **тариф рейса** (`/tarif`): остановки, цены, прибытие; тарифную категорию
  (действующая / новая с даты смены) выбирает сервер, а не адрес страницы;
- **контакты** (`/contacts`) и **текстовые страницы** меню и подвала
  (`/payways`, `/faq`, `/reglament-docs`, `/station-sogl-pd`,
  `/station-polit-pd`, `/moshenniki`, `/uslugi`, `/fz-223`, `/corrupt`):
  в legacy правились в исходниках, теперь лежат в базе и редактируются
  в админке АИС — содержимое визуально или в HTML с подсветкой, там же
  состав меню и загрузка файлов (PDF документов) на прежние адреса `/docs/...`.

Не перенесено: воркер писем (приглашение в кабинет ставится в очередь `emails`
и уйдёт после его запуска), форма «Написать сообщение», спец-тексты
фрахтования в печатных формах, АИС. Детали — в [PLAN.md](PLAN.md).
