# Legacy АИС автовокзалов — архитектура и подводные камни

Документ для агентов/разработчиков, которые сопровождают старый код или переносят его
на новый стек (Laravel/Nuxt/Postgres). Собрано по факту разбора рабочей системы.
Всё проверено на локально развёрнутом окружении сверки (см. §13).

---

## 1. Обзор компонентов

Три PHP-приложения на одном Windows-сервере (`192.168.2.5`), общая шара `htdocs`:

| Компонент | Папка | Что делает | Стек |
|-----------|-------|-----------|------|
| **Сайт** | `avtovokzal57/` | Публичный сайт: расписание, поиск, продажа билетов онлайн | процедурный PHP |
| **АИС** | `ais/` | Внутренняя система: отчёты, админка, бухгалтерия | процедурный PHP |
| **API продаж** | `ais/api/` | Прокси между сайтом и базами продаж (Firebird) | процедурный PHP |

Ключевая связь: **сайт не ходит в базы продаж напрямую** — он обращается к API продаж
(`ais/api/api2.php`) по HTTP, а тот уже работает с Firebird.

```
Браузер → avtovokzal57 (сайт) → HTTP → ais/api/api2.php → Firebird (BUSTER, продажи)
                                                          ↘ Сбербанк/ЮKassa (оплата)
```

---

## 2. Боевое окружение

- **ОС:** Windows 10 (1607), сервер `192.168.2.5`.
- **PHP:** 5.6.33 (XAMPP). Морально устарел — **код рассчитан на PHP 5.x** (нет `??`,
  `dirname($p,2)` и прочего из PHP 7+).
- **Apache:** отдаёт с `AddDefaultCharset cp1251` (см. §5 про кодировки).
- **DocumentRoot = `htdocs/ais`** — важнейший факт: `ais/agents.php` открывается по
  `http://192.168.2.5/agents.php` (без `ais/`), а `ais/api/api2.php` — по `/api/api2.php`.
  Сайт `avtovokzal57` — отдельный vhost/домен (`автовокзал57.рф`).

---

## 3. Базы данных

### 3.1. Вендорные Firebird 2.5 — базы продаж (НЕ менять)
- Имя базы: **`BUSTER`** (АСУ продажи билетов, ~222 таблицы).
- По одной базе на автостанцию. Адреса/порты — в массивах `$AWT` / `$ALT_IP` конфигов
  (`10.1.102.x/3050`, `192.168.4.x/43xxx` и т.п.).
- Структура вендорная, менять нельзя. Вся логика продажи — в **хранимых процедурах**
  (`KS_*`), см. §10.
- Кодировка базы: **WIN1251**.

### 3.2. Наши базы Firebird (переносим в Postgres)
- **`site`** — данные сайта: `ORDERS`, `BILETS`, `USERS`, `BACKS`, `EMAIL` (5 таблиц).
- **`ais`** — данные АИС: `KKM*`, `KSR_BACK`, `SALE_ALL`, `SB`, `SBERBANK*`, `VIRUCHKA` (12 таблиц).
- Структура «далека от оптимальной» (слова владельца) — при переносе проектируется заново.
- Кодировка: тоже WIN1251.

---

## 4. Класс `db_work` и подключения

`db_work` — самописная обёртка над БД. **Существует минимум в 3 копиях** (слегка отличаются):
- `ais/admin/inc/config.php` (для АИС) — класс на ~строке 637.
- `ais/api/config.php` (для API продаж).
- `avtovokzal57/inc/config.php` (для сайта) — конструктор с доп. параметром `$charset`.

### 4.1. Транспорт — ODBC (!)
Конструктор формирует ODBC-DSN и подключается через `odbc_connect`:
```php
$dbm = "DRIVER={Firebird/InterBase(r) driver}; [charSet=UTF-8;] UID=$user; PWD=$password; DBNAME=$host:$db";
$this->connection = odbc_connect($dbm, $user, $password);
```
На боевом сервере стоит Firebird ODBC-драйвер. Транзакции **ручные** (`odbc_autocommit(false)`),
фиксируются `commitT()` / откатываются `rollbackT()`.

### 4.2. Публичный интерфейс (что использует прикладной код)
- `new db_work($host,$db,$user,$password,$autocommit=false)`
- `->inArray($sql,$values,$regroup=false)` — **SELECT → массив ассоц.** (главный метод отчётов/чтения).
- `->insert($table,$data,$method,$multi,$commit)`
- `->doSql($sql,$values)` — INSERT/UPDATE/DELETE.
- `->commitT()` / `->rollbackT()` — управление транзакцией.
- Свойства: `->error`, `->errno`, `->obj_status`, `->connection`.

### 4.3. Как выбирается база продаж
- `$AWT[<код станции>]` содержит `db_host`, `db_name='buster'`, `db_user='SYSDBA'`, `db_password='1'`.
- Код станции (`awtcod`) — напр. `5701` (Орёл АВ), `5722` (Красная Заря).
- `$IP_MODE` (0/1) переключает набор адресов ($AWT ↔ $ALT_IP) для VPN/локального доступа.

---

## 5. Кодировки (частый источник «иероглифов»)

- **Базы Firebird — WIN1251.** Firebird умеет конвертировать в UTF-8 сам, если у
  СОЕДИНЕНИЯ указан `charSet=UTF8`.
- **ODBC-DSN уважает `charSet`:** разные конфиги задают его по-разному, и это НАДО учитывать:
  - `ais/config.php` (для `back.php`): `charSet=UTF-8` → данные приходят уже в UTF-8, код `iconv` НЕ делает.
  - `ais/admin/inc/config.php` (для `agents.php`): `charSet` закомментирован → данные сырые WIN1251,
    а страница сама вызывает `iconv('Windows-1251','UTF-8',...)` (15 раз в `agents.php`).
  - `avtovokzal57/inc/config.php`: `charSet` динамический (по умолчанию UTF-8).
- **Правило для агента:** прежде чем «чинить» кодировку — посмотри, есть ли `charSet` в DSN
  и делает ли страница `iconv`. Не ломай один сценарий, правя другой.
- Кодировка страницы: `ais` — `AddDefaultCharset cp1251` в `.htaccess`; отдельные скрипты
  шлют свой `Content-Type` (напр. `back.php` берёт charset из `$GLOBALS["setup"]["encoding"]`,
  который задаётся в `ais/config.php`).

---

## 6. Компонент: `avtovokzal57` (сайт)

- **Точка входа — `index.php`.** «Красивые» URL (`/buy`, `/passengers`, `/raspisanie-avtobusov`)
  разбираются самим `index.php` из `$_SERVER["REQUEST_URI"]` (строка ~25:
  `$url = mb_substr(trim($_SERVER["REQUEST_URI"]),1)`), затем `include('url/'.$url.'.php')`.
  На боевом маршрутизацию даёт rewrite в `.htaccess`.
- **Обработчики страниц — в `url/*.php`** (осторожно: там много копий с «— копия», их игнорировать).
- **Платёжный шлюз** — функция `setSbrf()` в `inc/config.php` (~строка 609): по дате поездки
  выбирает ЮKassa (после `new_gateway_date`) или Сбербанк. Ключи: `sbrf_server/userName/password`,
  `sbrf_returnUrl`, `sbrf_dynamicCallbackUrl`. Логика оплаты — в `inc/sbrf_help.php` (класс `sbrf_api`).
- **Клиент API продаж** — класс `tds_api` в `inc/api_help.php`. Метод `query()` шлёт POST через
  `file_get_contents` (НЕ curl) на `$CONFIG['awt_api_url']` (= `http://192.168.2.5/api/api2.php`).

---

## 7. Компонент: `ais` (АИС)

- Внутренние отчёты (напр. `agents.php` — агентские билеты, `back.php` — возвраты).
- Использует `admin/inc/config.php` (для админ-части) и `config.php` (для части скриптов).
- Отчёты читают базы продаж (`BUSTER`) и наши (`ais`) через `db_work->inArray`.
- Уже начат перевод в SPA v2 (`ais/apiv2/` + отдельный Vue-проект) — это отдельная ветка.

---

## 8. Компонент: API продаж (`ais/api/api2.php`)

Единый endpoint, `action`-роутинг. Тело запроса — JSON. Ключевые проверки на входе:
- **api_id + api_password** агента (`$AGENTS[api_id]`), напр. агент сайта `9984` / пароль `6666`.
- **Whitelist IP** агента: `$AGENTS[$api_id]['ip'][$_SERVER['REMOTE_ADDR']]` — доступ только с
  разрешённых адресов (`192.168.2.5`, `127.0.0.1` и т.п.). Ошибка «Для этого агента запрещён
  доступ с этого ip адреса» (err 4).

### Основные `action`:
| action | Назначение | Процедура Firebird |
|--------|-----------|--------------------|
| `download_list_of_routes` | список рейсов на дату (поиск) | `KS_...` (расписание) |
| `free_places` | свободные места на рейс | `KS_ALL_PLACES` |
| `start_buy` | **зарезервировать места** (бронь на время) | `KS_BRONTIME_PLACE(...,24)` |
| `end_buy` | **продать зарезервированные места** (оформить билет) | `KS_SALE_PLACES(...)` |
| `raice_status` | статус рейса | — |

Ответ — JSON `{success:bool, error, error_code, data:...}`.

---

## 9. Поток покупки билета (end-to-end)

Самая сложная цепочка. Пошагово:

1. **Поиск** (`/`, форма `FindRouteForm`): сайт → `tds_api` → `api2.php action=download_list_of_routes`
   → список рейсов из BUSTER. Ошибка «Не удалось получить информацию с …» = падение на этом шаге
   (см. `url/index.php`, catch на ~строке 386).

2. **Пассажиры** (`url/passengers.php`): ввод ФИО.
   - Вызывает `api2.php action=start_buy` → процедура **`KS_BRONTIME_PLACE`** резервирует места.
   - Резерв пишется в таблицу **`SALONINFO`** (поле `TIMEMARK` = метка времени резерва), фиксируется
     `commitT()`.
   - Ответ содержит **`ID_TIME_PLACES`** (= `TIMEMARK` резерва).
   - `passengers.php` создаёт заказ в **`site.ORDERS`** (`->insert('ORDERS',...)`), где
     **`ZAKAZ = $DATA['ID_TIME_PLACES']`** (строка ~376). То есть `ZAKAZ` == `TIMEMARK` резерва.

3. **Оплата** (Сбербанк/ЮKassa): регистрируется заказ, пользователь уходит на шлюз, оплачивает,
   возвращается на `sbrf_returnUrl` (`/payconfirm?orderId=...`).

4. **Подтверждение** (`url/payconfirm.php`):
   - По `orderId` находит заказ: `select * from ORDERS where SBERBANK=?`.
   - Проверяет оплату через `sbrf_api->query('getOrderStatusExtended_do')` (`orderStatus==2` = оплачено).
   - Вызывает `api2.php action=end_buy` → процедура **`KS_SALE_PLACES`** оформляет билет:
     - Ищет резерв в `SALONINFO` по (`awtcod, napcod, raicecod, n_place, awtsalecod, wrkcod`) И
       **`cast(timemark as varchar(25)) = :id_time_places`** (где `id_time_places` = `ZAKAZ` заказа)
       И `timemark >= current_timestamp - 30 мин`.
     - Если резерв найден — продаёт место (INSERT в `SALE`, `TYPE_OPER=0`), возвращает `S_TICKETS`.
     - Если не найден — `ERR_CODE=5`, «место оформить нельзя: превышено время ожидания…».
   - При успехе обновляет `ORDERS`: `BUY_END=1, STATUS=1, BILETS=..., BACK_CODE=...`.

**Итог успешной покупки:** `site.ORDERS.BUY_END=1` и запись в `BUSTER.SALE` (`TYPE_OPER=0`) с номером билета.

---

## 10. Ключевые процедуры Firebird (BUSTER)

Вся бизнес-логика продаж — в хранимых процедурах `KS_*`. Их **нельзя менять** (вендорные).
Важные для покупки:

- **`KS_BRONTIME_PLACE(..., bron_time_in_min)`** — резерв мест.
  - Внутри: `bron_time_in_min = bron_time_in_min - 30` (эмпирическая коррекция!), затем
    `time_to_bron_time = dateadd(minute, bron_time_in_min, current_timestamp)`.
  - Пишет резерв в `SALONINFO` с `TIMEMARK = time_to_bron_time`. Возвращает `NN_PLACES_BRON_TRUE`
    (забронированные места) и `ID_TIME_PLACES` (= TIMEMARK).
- **`KS_SALE_PLACES(...)`** — продажа зарезервированных мест (оформление). Условие поиска резерва —
  см. §9 шаг 4. Возвращает `S_TICKETS` (номера) или `ERR_CODE`/`COMMENT_TXT`.
- **`KS_ALL_PLACES(...)`** — все/свободные места рейса.
- **`KS_STATIONS(...)`**, **`KS_ANN_TICKET_FROM_AW(...)`**, `SH0_SALE_BAG_ON_RAICE(...)` (багаж) и др.

Исходник процедуры можно прочитать так (blob):
```php
$q=ibase_query($ib,"SELECT RDB\$PROCEDURE_SOURCE FROM RDB\$PROCEDURES WHERE RDB\$PROCEDURE_NAME='KS_SALE_PLACES'");
$row=ibase_fetch_row($q); $bh=ibase_blob_open($row[0]);
while(($c=ibase_blob_get($bh,8192)) && $c!=='') echo $c;
```

---

## 11. Механизм резерва мест (критично понимать)

- Резерв живёт в **`SALONINFO`**, ключевое поле — **`TIMEMARK`** (timestamp с долями секунды,
  напр. `2026-08-03 02:14:48.5110`).
- `start_buy` создаёт `TIMEMARK`; `end_buy` ищет резерв, сверяя `TIMEMARK` с `id_time_places`
  (= `ZAKAZ` заказа) **как строку** (`cast(timemark as varchar(25)) = :id_time_places`) плюс
  проверку «не старше 30 минут».
- `ID_TIME_PLACES` в процедуре имеет тип **TIMESTAMP** → сравнение чувствительно к точности
  долей секунды (см. §14 про ibase).
- Резерв привязан к агенту/работнику (`AWTSALECOD`, `WRKCOD`) и рейсу (`AWTCOD/NAPCOD/RAICECOD/N_PLACE`).
  Все эти поля должны совпасть, иначе резерв «не найден».

---

## 12. Локальное окружение для сверки

Развёрнуто в `avtovokzal_new_platform/` (docker compose). См. `README.md`. Кратко:
- **Firebird 2.5** (`jacobalberty/firebird:2.5-ss`) с восстановленными из бэкапов базами
  `buster` (Красная Заря), `site`, `ais` — алиасы в `docker/firebird/aliases.conf`.
- **legacy** (php 5.6 + Apache) — АИС `ais/` на `:8090`.
- **legacy_site** (тот же образ) — сайт `avtovokzal57/` на `:8091`.
- Боевой код лежит в `./htdocs` (распакованный бэкап), правится ТОЛЬКО локально.
- Восстановление .fbk: `gbak -c -n -o -user SYSDBA -password masterkey <файл> /firebird/data/<имя>.fdb`
  (флаг `-n` обходит NOT NULL в вендорных бэкапах).

### `odbc-shim.php` — сердце локальной совместимости
Legacy-код написан под ODBC-драйвер Firebird, которого нет под Linux. Вместо него подключён
глобальный шим (`auto_prepend_file`), эмулирующий `odbc_*` через **нативный Firebird** (`ibase_*`):
- Перенаправляет все подключения на локальный контейнер `firebird` (по `db_name` → алиас).
- Уважает `charSet` из DSN.
- Держит **явную транзакцию** на соединение (иначе `commitT` не фиксирует).
- **Инлайнит timestamp-с-миллисекундами прямо в SQL** (иначе ibase теряет доли секунды — см. §14).

---

## 13. Подводные камни (must-read перед правками)

1. **ODBC под Linux нет.** Локально работает через `odbc-shim.php` (ibase). Если пишешь новый код —
   учитывай нативный Firebird, а не ODBC.
2. **Кодировка зависит от `charSet` в DSN** (§5). Не «чини» глобально — сломаешь другой сценарий.
3. **Транзакции ручные.** Резерв/продажа требуют `commitT()`. Без корректного commit изменения
   откатываются при закрытии соединения (в shim это решено явной транзакцией).
4. **`ibase` теряет доли секунды** при bind строки в TIMESTAMP-параметр (`.5110` → `.0000`).
   Ломает сверку резерва по `TIMEMARK`. В shim обойдено инлайном timestamp-литералов. Боевой
   ODBC доли секунды сохранял.
5. **Часовые пояса.** Firebird `CURRENT_TIMESTAMP` и PHP `date()` должны быть в одной зоне (МСК),
   иначе метки резерва «уезжают» и бронь кажется истёкшей. В контейнерах — `TZ=Europe/Moscow`.
6. **IP whitelist API.** `api2.php` пускает агента только с разрешённых IP. Локально добавлена
   docker-подсеть (`172.16/12`, `127.`, `10.`). Боевые IP не трогать.
7. **Роутинг сайта** — через `$_SERVER["REQUEST_URI"]` внутри `index.php`. Локально нужен
   `FallbackResource /index.php` (боевой `.htaccess` содержит и rewrite, и redirect на прод-домен).
8. **`.htaccess`** боевого `ais` содержит `Deny from all` (доступ только из офисных подсетей) и
   `AddDefaultCharset cp1251`. Локально игнорируется (`AllowOverride None`).
9. **DocumentRoot = `ais`.** Файлы `ais/*` открываются по корню URL. API — `/api/api2.php`.
10. **Firebird-алиасы.** `db_name` в конфигах = `buster`/`site`/`ais` — резолвятся через
    `aliases.conf`. Регистр обычно неважен, но проверяй.
11. **Резерв ↔ заказ связаны по `ZAKAZ = ID_TIME_PLACES = SALONINFO.TIMEMARK`.** Любой сдвиг
    (точность, зона) рвёт эту связь → «нет билетов / место недоступно».
12. **Много мусора в репо:** файлы «— копия», `.txt`-логи, старые `api*.php`. Актуальны:
    сайт `index.php`+`url/*`, API `ais/api/api2.php`, конфиги (§4).

---

## 14. Диагностика поломок оформления билета

Если «нет билетов / превышено время ожидания» при оплате:
1. Найти заказ: `SELECT ZAKAZ, FROM_ID, DATESTART, NAPCOD, RAICECOD, STATCODE FROM ORDERS WHERE SBERBANK='<orderId>'` (база `site`).
2. Проверить резерв: `SELECT * FROM SALONINFO WHERE N_PLACE=? AND AWTCOD=? AND NAPCOD=? AND RAICECOD=? AND CAST(TIMEMARK AS VARCHAR(25))='<ZAKAZ>'` (база `buster`).
3. Сверить условие `end_buy` целиком (все поля §9 шаг 4 + `TIMEMARK >= current-30`).
4. Временный debug: `error_log(...)` в `api2.php` перед `throw` в ветках `start_buy`/`end_buy`
   (лог виден через `docker compose logs legacy`). **Не** через файл — у www-data нет прав на bind-mount.
5. Помни про точность TIMESTAMP (§13.4) и зоны (§13.5).

---

## 15. Карта ключевых файлов

```
avtovokzal57/
  index.php                 # точка входа, роутинг по REQUEST_URI
  inc/config.php            # $AWT, db_work, setSbrf(), tds_api-настройки
  inc/api_help.php          # class tds_api (клиент API продаж, file_get_contents)
  inc/sbrf_help.php         # class sbrf_api (оплата)
  url/passengers.php        # ввод пассажиров + start_buy + INSERT ORDERS
  url/index.php             # обработчик поиска рейсов (FindRouteForm)
  url/buy.php               # шаг выбора мест/пассажиров
  url/payconfirm.php        # подтверждение оплаты + end_buy + оформление

ais/
  agents.php, back.php, ... # отчёты
  config.php                # db_work (charSet=UTF-8), $setup[encoding]
  admin/inc/config.php      # db_work (без charSet), $AWT, класс на ~637
  api/api2.php              # API продаж (actions, whitelist, KS_* процедуры)
  api/config.php            # db_work для API
  apiv2/                    # начатый SPA-бэкенд v2 (отдельная ветка)
```
