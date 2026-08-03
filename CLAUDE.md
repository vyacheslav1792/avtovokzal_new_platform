# CLAUDE.md — avtovokzal_new_platform

Инструкции для Claude Code по работе с этим репозиторием. Читай вместе с
[README.md](README.md), [PLAN.md](PLAN.md) и [docs/LEGACY.md](docs/LEGACY.md) — здесь только
то, что не выводится из кода: правила, ловушки и как что запускать.

## Что это

Модернизация АИС автовокзалов. Три легаси-компонента (сайт `avtovokzal57`, АИС `ais`,
API продаж `ais/api`) переводим на **PHP 8.5 / Laravel 13 / Nuxt / PostgreSQL 17** в Docker
Compose. Вендорные Firebird-базы продаж (BUSTER и др.) **не трогаем** — структура вендорная.
Идёт **этап переноса**: покупка, возврат по коду, печатные формы и фискализация уже
работают на новом стеке (сквозные прогоны 2026-08-03); статус по этапам — в PLAN.md.

## Правила (обязательно)

- **НЕ упрощать функциональность legacy при переносе без ЯВНОГО разрешения
  пользователя** и не делать допущений, не соответствующих реальности
  («аналог», «примерно то же») — изучить исходник целиком, найти все отличия
  и воспроизвести. Если кажется, что что-то можно упростить/выкинуть —
  сначала спросить. Явно разрешённые упрощения фиксировать здесь
  (разрешено: защитный код 123321 в back_bilet.php не переносим —
  позже будет нормальная авторизация; IP-ограничения агентов в legacy
  на локальном контуре убраны).
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
(**API продаж**, `sales-api/`, отдельная БД Postgres `sales`), kkm_web :8087 (**KKM API**,
`kkm-api/`, БД Postgres `kkm`), ais_web :8086 (**AIS API**, `ais-api/`, БД Postgres `ais`;
:8082 на хосте занят посторонним Apache), frontend :3000 (Nuxt + Nuxt UI), ais_spa :3001
(SPA отчётов АИС), db :5432, firebird :3050, legacy АИС :8090 (`ais/`), legacy сайт :8091
(`avtovokzal57/`).
**KkmServer** установлен на хост-машине (НЕ docker): systemd `kkmserver.service`,
http://127.0.0.1:5893, виртуальная ККТ NumDevice=1 (эмулятор); из контейнеров доступен
как `host.docker.internal:5893` (extra_hosts host-gateway у kkm_app).
Таблица сервисов — в README.

### Новые сервисы (этап переноса, август 2026)
- **`sales-api/`** — API продаж (Laravel 13): рейсы/места/резерв/продажа/возврат через
  процедуры KS_* Firebird. Работает автономно, без оплат. Журнал всех запросов/ответов —
  Postgres `sales.api_requests`; резервы/продажи/возвраты — свои таблицы. Агенты — Bearer-токен
  (`config/agents.php`) + IP whitelist. Ловушка ID_TIME_PLACES (доли секунды) решена инлайном
  литерала (`BusterConnection::quoteTimestamp`).
- **`backend/`** — высокоуровневое API: заказы (`orders`), оплата Сбербанк через SDK
  `voronkovich/sberbank-acquiring-client` (локально тестовый контур 3dsec/metagraphica),
  подтверждение оплаты + оформление, возврат по коду (`refunds/preview|confirm`, таблица
  `refunds`, подписанный Crypt-токен подтверждения), данные печати (`orders/{id}/print`,
  обезличивание 30 дней), билеты одним PDF (`orders/{id}/pdf`).
  Чек ККМ и письмо ставятся в database-очередь (`receipts`/`emails`):
  receipts обрабатывается через KKM API (воркер запускаем вручную:
  `queue:work --queue=receipts --stop-when-empty`), **воркер писем сознательно не запущен**.
  **`app.timezone` = Europe/Moscow** — времена рейсов BUSTER wall-clock; на UTC дедлайны
  «за 30 минут» уезжают на +3 ч, не менять. **Вместе с ним обязателен
  `'timezone' => Europe/Moscow` у соединения pgsql** (`config/database.php`): Eloquent
  пишет даты строкой БЕЗ смещения, и сессия Postgres в UTC принимает московское время
  за UTC — все timestamptz уезжают на +3 ч (ловилось на времени прибытия в билете:
  01:30 следующих суток вместо 22:30). То же — у ais-api (оба соединения, включая
  read-only `site`: отчёты режут выборку по московским суткам). sales-api и kkm-api
  живут в UTC — там строка не нужна.
- **`kkm-api/`** — KKM API (Laravel 13, порт :8087): прослойка к KkmServer для фискальных
  чеков, по образцу sales-api (Bearer-токен `config/clients.php` + IP whitelist, журнал
  `kkm.api_requests`). Таблица `receipts`: **external_id — ключ идемпотентности клиента**
  (повтор не пробивает второй чек), IdCommand — идемпотентность внутри KkmServer
  (перед повторной отправкой всегда GetRezult). ЛОВУШКА GetRezult: внешний Status —
  статус самого GetRezult, результат исходной команды вложен в `Rezult` со своим Status.
  Backend шлёт чеки с ключами `sale:{uuid}` / `refund:{uuid}`; суммы позиций чека обязаны
  сходиться с оплатой до копейки (в чеке возврата разница построчных округлений
  компенсируется последней позицией). Смена >24 ч (54-ФЗ) закрывается автоматически:
  при отказе ККТ сервис распознаёт истёкшую смену (текст ошибки или GetDataKKT
  SessionState=3), пробивает Z-отчёт CloseShift и повторяет чек; новая смена
  открывается первым же чеком. Плюс ночной Z-отчёт в 23:59 МСК: команда
  `kkm:close-shift`, запускает **ofelia** (сервис `scheduler` в compose,
  `docker/ofelia/config.ini`, job-exec в контейнер `avtovokzal-kkm-app`) —
  отдельный php-контейнер под schedule:work НЕ поднимаем.
- **`frontend/`** — Nuxt 4 + **Nuxt UI 4**: страницы `/` (поиск + hero bus.jpg + логотип из
  legacy), `/trips`, `/buy`, `/payment/result`, `/backbycode` (возврат по коду, текст
  legacy 1:1, принимает `?code=`), `/print/[id]` (печатные формы A4: билет + багажные
  квитанции; у ПОСЛЕДНЕГО бланка `page-break-after: auto` — иначе печать/PDF
  добавляют пустую страницу). PDF билетов рендерит **gotenberg** (сервис в compose,
  Chromium за HTTP API) по URL этой же страницы — как legacy `pdf.php` гнал
  `print.php` через wkhtmltopdf; кэш файла на диске не держим, в отличие от legacy.
  Наружу gotenberg не публикуем, ходит по внутренней сети (`--chromium-allow-list`).
  Ссылки ВНУТРЬ печатных форм (возврат по коду) берём из
  `runtimeConfig.public.siteUrl` (`NUXT_PUBLIC_SITE_URL`), а НЕ из
  `useRequestURL()`: при рендере PDF origin равен внутреннему
  `http://frontend:3000` и такой адрес попадает на бумагу.
  «Популярные направления» на главной фильтруются по станциям из конфига —
  плитки недоступных станций скрываются сами. SSR ходит в backend по `http://web`
  (runtimeConfig.apiBaseServer), браузер — на :8080.
- **Билеты сайта — таблица `tickets`, НЕ jsonb**: строка на пассажирский билет
  (`Order::tickets()`, порядок по `position`; багажные квитанции — jsonb в строке
  билета, их ≤5 и по номерам не ищем). Колонки `orders.tickets` больше нет —
  новый код по билетам ходит только через relation. `passengers` (jsonb) остаётся:
  это ввод покупателя до оформления. Индексы под 30–50 тыс. покупок/мес уже
  расставлены (tickets serial+number, orders created_at/date_start/awtcod,
  refunds order_id/created_at) — при новых отчётах сначала смотреть их.
- **`ais-api/`** — API отчётов АИС (Laravel 13, :8086): три источника данных —
  Firebird станций (`StationConnection`, charset=UTF8, iconv из legacy НЕ нужен),
  Postgres сайта (connection `site` → БД `avtovokzal`, read-only) и своя БД `ais`.
  **Конфиг станций ОБЩИЙ**: `shared/stations.php` монорепо (mount `/var/www/shared`),
  `config/stations.php` sales-api и ais-api делают `return require` — НЕ дублировать,
  как в legacy ($AWT лежал в трёх местах). В конфиге только реально развёрнутые базы
  (фантомных станций, смотрящих в чужую базу, быть не должно); у станции ВСЕ поля
  значимы — buh/buh_st/buh_ksr (бухгалтерия), stationkey, inlist — не упрощать.
  Боевые реквизиты невключённых станций сохранены закомментированными. Отчёты: класс + строка в `config/reports.php`,
  контракт reports/meta/run совместим с legacy apiv2 (SPA не меняется). При переносе
  отчётов повторять набор колонок SQL 1:1 — отсутствие колонки в legacy (IS_PRN,
  STRWANT_BACK) влияет на итоги; «Агентские билеты» сверен с legacy построчно.
  Отчёт «Продажи сайта» (`site-sales`) — перенос `ais/in.php` И `ais/back.php`
  (режим «по продажам»/«по поездкам» — смысл дат и сортировка) по данным НОВОГО
  сайта (Postgres site: orders/tickets/refunds), не legacy Firebird. Ссылки как
  в legacy: статус → печать (SITE_FRONTEND_URL из `config/site.php`), заказ и
  билет → `/back-ticket` в ais-spa (перенос `ais/back_bilet.php`). Рендер SPA
  расширен (обратная совместимость): `_class` строки — подсветка
  (row-green/yellow/orange/red/blue/gray), колонка type=link — `{text, url}`.
  Сводная «по станциям и агентам» из in.php ещё НЕ перенесена.
- **Админский возврат** (перенос `ais/back_bilet.php` 1:1, см. «Правила» об
  упрощениях): backend `POST /api/v1/admin/refunds/inspect|confirm|repair` +
  страница ais-spa `/back-ticket` (vite-proxy `/site-api` → web). Отличия от
  возврата по коду: без дедлайна и после отправления рейса (`admin_mode` в
  sales-api /refunds пропускает проверку IS_PRN — как legacy), сверка оплаты
  `getOrderStatusExtended` до возврата, процент удержаний выбирает админ
  (0/5/15/25 + рекомендуемый от станции; на вендорную ведомость процент НЕ
  влияет — в legacy `shtraf` до KS_ANN_TICKET_FROM_AW не доходил), починка
  зависшего возврата денег. Формула суммы — как в back_bilet (number_format,
  округление round), НЕ как в backbycode (floor). БЕЗ авторизации — на прод
  не открывать (авторизация на этапе users).
- **`ais-spa/`** — SPA отчётов АИС (Vue 3 + Vite, :3001 dev): перенос из устаревшего
  репозитория `avtovokzal_ais_spa` (тот был под legacy apiv2). `/api` → ais_web через
  vite-proxy. Авторизации пока нет (внутренний контур) — добавить на этапе users/ЛК.

### Валидация данных пассажира (не ослаблять!)
- Правила серии/номера документа сняты 1:1 с вендорной процедуры
  **`KS_CONTROL_DATA_PAS`** (исходник — `docs/buster-procedures/`) и выполняются
  ДО оплаты: `backend/app/Services/PassengerDocument.php` + `StoreOrderRequest::after`.
  При изменениях сверять скриптом (18 кейсов против живой процедуры — см. историю
  коммита «Валидация документов по правилам станции»).
- ФИО: BUSTER не проверяет и молча режет до 20/14/14 — лимиты и фильтр символов
  (буквы/дефис/пробел/апостроф) держим на backend. **Ё всегда заменяется на Е**
  (`OrderService::normalizeName`) — буква Ё ломает вендорный софт станции.
- Справочник гражданств — таблица `citizenships` (коды COUNTRIES_TABLE, 185 = Россия),
  сид `CitizenshipSeeder` из `database/data/citizenships.json`. В кэш класть только
  массивы: Eloquent Collection в database-кэше десериализуется в incomplete class.

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

## Проект AIS SPA

СПА отчётов АИС живёт в монорепо: `ais-spa/` (см. «Новые сервисы»). Соседний
репозиторий `/home/developer/PROJECTS/avtovokzal_ais_spa/` **устарел** (работал
поверх legacy apiv2) — исходники перенесены сюда 2026-08-03, новые правки только
в `ais-spa/`. `dist/` в git не пушим.
