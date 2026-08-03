# CLAUDE.md — avtovokzal_new_platform

Инструкции для Claude Code по работе с этим репозиторием. Читай вместе с
[README.md](README.md), [PLAN.md](PLAN.md) и [docs/LEGACY.md](docs/LEGACY.md) — здесь только
то, что не выводится из кода: правила, ловушки и как что запускать.

## Что это

Модернизация АИС автовокзалов. Три легаси-компонента (сайт `avtovokzal57`, АИС `ais`,
API продаж `ais/api`) переводим на **PHP 8.5 / Laravel 13 / Nuxt / PostgreSQL 17** в Docker
Compose. Вендорные Firebird-базы продаж (BUSTER и др.) **не трогаем** — структура вендорная.
Сейчас идёт **этап 1** (окружение); логика приложений ещё не переписывается.

## Правила (обязательно)

- **Боевой legacy-код правим ТОЛЬКО в локальной копии `./htdocs`** (распакованный бэкап).
  Никогда — на CIFS-шаре `/mnt/htdocs`.
- **На шаре `/mnt/htdocs` НЕ использовать `perl -i` / `sed -i`** — файл удаляется. Только
  прямая перезапись через Write. (См. память `cifs-no-inplace-edit`.)
- **Именованные тома Docker не используем** — только bind mounts (`./data`, `./htdocs`, …).
- **`dist/` и `data/postgres/` не коммитим** — это артефакты сборки/данные.
- **Тестовая покупка билета:** всегда с багажом, по 2–3 билета за раз. При неоднозначности —
  **остановиться и спросить** пользователя.
- Тестовые креды (SMB `admin/avtovokzal57`, шлюз Сбербанка `metagraphica/metagraphica`,
  тест-карта) — только для локальной разработки, наружу не публиковать.

## Окружение

```bash
docker compose up -d          # поднять стек
docker compose ps             # статус
docker compose logs -f <svc>  # логи
docker compose down           # остановить (данные Postgres в ./data сохранятся)
```

Порты: web :8080 (backend, высокоуровневое API avtovokzal57), sales_web :8085
(**API продаж**, `sales-api/`, отдельная БД Postgres `sales`), frontend :3000 (Nuxt + Nuxt UI),
db :5432, firebird :3050, legacy АИС :8090 (`ais/`), legacy сайт :8091 (`avtovokzal57/`).
Таблица сервисов — в README.

### Новые сервисы (этап переноса, август 2026)
- **`sales-api/`** — API продаж (Laravel 13): рейсы/места/резерв/продажа/возврат через
  процедуры KS_* Firebird. Работает автономно, без оплат. Журнал всех запросов/ответов —
  Postgres `sales.api_requests`; резервы/продажи/возвраты — свои таблицы. Агенты — Bearer-токен
  (`config/agents.php`) + IP whitelist. Ловушка ID_TIME_PLACES (доли секунды) решена инлайном
  литерала (`BusterConnection::quoteTimestamp`).
- **`backend/`** — высокоуровневое API: заказы (`orders`), оплата Сбербанк через SDK
  `voronkovich/sberbank-acquiring-client` (локально тестовый контур 3dsec/metagraphica),
  подтверждение оплаты + оформление, чек ККМ и письмо ставятся в database-очередь
  (`receipts`/`emails`) — **воркер сознательно не запущен**.
- **`frontend/`** — Nuxt 4 + **Nuxt UI 4**: страницы `/` (поиск + hero bus.jpg + логотип из
  legacy), `/trips`, `/buy`, `/payment/result`. SSR ходит в backend по `http://web`
  (runtimeConfig.apiBaseServer), браузер — на :8080.

### Legacy через ODBC-shim

Боевой код работает в контейнерах `legacy`/`legacy_site` **без правок**: класс `db_work`
написан под ODBC, а `docker/legacy/odbc-shim.php` (`auto_prepend_file`) эмулирует `odbc_*`
через нативный Firebird и перенаправляет все подключения на контейнер `firebird`.

**Ловушки shim, уже учтённые (не сломать при правках):**
- Явные транзакции: `ibase_trans` + `commitT` — без коммита INSERT/UPDATE не сохраняются
  (симптом: оплата прошла, а заказ «не оформлен»).
- TIMESTAMP теряет миллисекунды при биндинге ibase → таймстемпы **инлайнятся прямо в SQL**.
- `charSet` берётся из DSN (как боевой драйвер): конфиги с `charSet=UTF-8` отдают UTF-8,
  без — сырой WIN1251 (страница сама делает `iconv`).
- **Таймзоны:** Firebird и PHP должны быть в **+3** (`TZ=Europe/Moscow`), иначе payconfirm
  падает на сверке времени резерва.

## Базы

- **PostgreSQL** (`db`) — наши данные (`site`, `ais` после переноса), `./data/postgres`.
- **Firebird 2.5** (`./databases`, восстановлены из бэкапов): `BUSTER` (buster.fdb, Красная
  Заря, вендорная), `ais` (ais.fdb), `site` (site.fdb).
  Восстановление: `gbak -c -n -o -user SYSDBA -password masterkey <файл.fbk> /firebird/data/<имя>.fdb`
  (`-n` обходит NOT NULL в вендорных бэкапах).

isql к нашим базам:
```bash
docker compose exec -T firebird /usr/local/firebird/bin/isql -user SYSDBA -password masterkey site
```

## Поток покупки билета

Полное описание — [docs/LEGACY.md](docs/LEGACY.md) §9–§13. Кратко:
`search → start_buy` (резерв места `KS_BRONTIME_PLACE` в SALONINFO) → `ORDERS insert` →
оплата Сбербанк → 3-DS → `payconfirm` → `end_buy` (`KS_SALE_PLACES`) → SALE.

Сквозной прогон покупки через браузер автоматизирован — см. **скилл `buy-ticket-test`**
(`.claude/skills/buy-ticket-test/`): предусловия (Chrome :9223, MCP chrome-devtools),
тестовые данные и все ловушки формы оплаты.

## Проект AIS SPA (соседний репозиторий)

`/home/developer/PROJECTS/avtovokzal_ais_spa/` — Vue SPA-версия отчётов АИС (v2). Билд
локально, на сервер выкладывается только собранная версия; `dist/` в git не пушим.
