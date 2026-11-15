# Деплой и прод-окружение (orelavtotrans)

Как проект развёрнут в проде и как его обновлять. Прод стоит на отдельном
сервере в локальной сети, наружу доступен по доменам через проброс портов
80/443 на роутере. Адреса сервера:

- `91.202.165.5` — публичный (по нему же сервер выходит в интернет);
- `192.168.2.55` — основной LAN-адрес (DHCP), шлюз `192.168.2.100`;
- `192.168.2.58` — дополнительный статический адрес SMTP (см. «Почта»);
- `192.168.2.200` — дополнительный статический адрес обменника.

Прежние адреса сервера (`192.168.0.75` в LAN, публичный `109.61.151.34`)
встречаются в старых записях — это **тот же сервер** до смены сети.

## Домены и маршрутизация

Единая точка входа снаружи — **системный nginx на хосте** (не в docker). Он
терминирует TLS и проксирует на сервисы, слушающие только на `127.0.0.1`.
Внутренние сервисы (Postgres, Firebird, gotenberg) наружу не публикуются.

| Домен | Назначение | Куда проксируется |
|---|---|---|
| `автовокзал57.рф` (`xn--57-6kcaja9axlzb9b.xn--p1ai`) | **Сайт (основной адрес)** | `/` → frontend (3000), `/api/v1/` → web (8088) |
| `site.orelavtotrans.ru` | Сайт (прежний адрес, работает) | то же самое |
| `ais.orelavtotrans.ru` | Админка/отчёты АИС | `/` → ais_spa (3001), `/api/` → ais_web (8086), `/site-api/` → web (8088) |
| `deploy.orelavtotrans.ru` | Панель деплоя | `/` → контроллер (8099), `/agent` → php-fpm (исполнитель) |

**15.11.2026 домен `автовокзал57.рф` отдан новой платформе.** До этого он
целиком проксировался на старый сервер `192.168.2.5` (прежний конфиг
сохранён как `…conf.legacy-proxy.bak`). Станционная система старого сайта
(`/stations/**`) по-прежнему живёт там и проксируется уже самим Nuxt —
см. CLAUDE.md и PLAN.md §9.

Конфиги vhost'ов — `/etc/nginx/sites-available/*.orelavtotrans.ru.conf`
(в репозитории проекта не хранятся — это серверная инфраструктура).
`/api/v1/*` на сайте уходит на backend, остальное — на Nuxt (у Nuxt свои
серверные маршруты `/api/auth/*`), как раньше делал Caddy.

## TLS (Let's Encrypt)

Сертификаты на все три домена выпущены `certbot` по HTTP-01 (webroot
`/var/www/acme`, challenge отдаёт отдельный `:80`-блок, он же редиректит на
https). Автопродление — системный таймер `certbot.timer`.

Важно про сеть: входящий **80 работает** (нужен для HTTP-01), поэтому продление
автоматическое. Если провайдер/роутер перекроет 80 — переключиться на DNS-01.

## Прод-сборка: `docker-compose.prod.yml`

Прод — это оверрайд поверх базового (dev) `docker-compose.yml`:

- **frontend (Nuxt)**: вместо dev-сервера (`npm run dev`) запускается собранный
  боевой SSR — `node .output/server/index.mjs`. У него нет host-check Vite.
- **ais_spa (Vite SPA)**: собранная статика отдаётся через `vite preview`;
  `/api` и `/site-api` проксирует системный nginx (в dev это делал Vite-прокси).
- **Caddy и legacy** (`proxy`, `legacy`, `legacy_site`) в проде не поднимаются.
- Всем сервисам задан `restart: unless-stopped` (переживают перезагрузку).
- Именованных томов нет — только bind-mount'ы, как и в базовом файле.

На сервере оверрайд включён через `COMPOSE_FILE` в `.env` рабочей копии:

```
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
```

поэтому обычный `docker compose ...` уже использует прод-набор. (`.env` в git не
хранится.)

### Как собираются фронты

Node на хосте не установлен — сборка идёт **во временном контейнере**
(`node:24-alpine`) с bind-mount каталога проекта, поэтому артефакты (`.output`,
`dist`, `node_modules`) ложатся на диск хоста:

```bash
docker compose run --rm --no-deps frontend sh -lc "npm install && npm run build"
docker compose run --rm --no-deps ais_spa  sh -lc "npm install && npm run build"
docker compose up -d
docker compose restart frontend ais_spa
docker compose restart scheduler   # если менялся docker/ofelia/config.ini
```

`.output`/`dist` живут в bind-mount, поэтому после перезагрузки контейнеры
стартуют сразу готовой сборкой, без пересборки.

**ЛОВУШКА: без последней строки сборка не выкатывается.** `docker compose up -d`
пересоздаёт контейнер, только если изменился его конфиг, — а при обычном деплое
у `frontend` и `ais_spa` он тот же. Запущенные процессы
(`node .output/server/index.mjs` и `vite preview`) продолжают держать **прошлую**
сборку: деплой доходит до «Готово» без единой ошибки, а сайт отдаёт старый бандл.
Ловилось 14.11.2026 на чисто фронтовом деплое — в выдаче остался зелёный
`--color-green-500` вместо новой фирменной палитры. Поэтому `restart` фронтов
дописан и в деплой-скрипт (бэкап `updateAvtovokzalFromGit.sh.bak_20261114`).
Проверять результат деплоя стоит по самой выдаче
(`curl https://автовокзал57.рф/_nuxt/entry.*.css`), а не по строке «Готово»
в логе.

**Та же ловушка у планировщика.** ofelia читает `docker/ofelia/config.ini`
ТОЛЬКО при старте, а файл приезжает bind-mount'ом — новые и изменённые
задания без рестарта в расписание не попадают. `docker compose restart
scheduler` тоже дописан в деплой-скрипт (бэкап
`updateAvtovokzalFromGit.sh.bak_20261114_scheduler`). Проверка —
`docker compose logs scheduler | grep "New job registered"`.

Нюанс сети: `@nuxt/fonts` на сборке тянет шрифты; `api.fontshare.com` с сервера
недоступен (нефатальный таймаут ~24с), остальные шрифты качаются с
`fonts.gstatic.com` — сборка проходит.

### Владелец файлов

`git pull` на деплое выполняется от **root**, а контейнеры работают под uid
`1000` — поэтому деплой-скрипт после pull делает `chown -R 1000:1000` на кодовые
каталоги, иначе `npm`/`composer` в контейнерах падают с `EACCES`.

## Режим обслуживания

На время работ, которые ломают работу сайта на минуты (миграция с правкой
данных, `legacy:import-site`, ручная починка), backend переводится в режим
обслуживания:

```bash
docker compose exec app php artisan down --retry=120
# …работы…
docker compose exec app php artisan up
```

Что при этом видит покупатель: сайт открывается и читается (страницы,
контакты, сохранённые билеты PWA), но наверху стоит полоса «Техническое
обслуживание» с текстом из `site.maintenance_text`, а форма покупки не
работает. Страница отдаётся с кодом **503 и `Retry-After`** — для
поисковика это «вернись позже», а не «сайт так и выглядит».

Техника: `artisan down` отдаёт для `api/*` JSON с признаком `maintenance`
(`bootstrap/app.php`), фронт различает его от настоящей поломки
(`useApiOutage`), а код ответа ставит серверный плагин Nuxt
`plugins/api-outage.server.ts`. Подробности и ловушки — в CLAUDE.md,
раздел «Режим обслуживания».

**Обычный деплой в этом режиме не нуждается:** сборка фронтов идёт во
временном контейнере, а рестарт `frontend`/`web` занимает секунды. Режим
нужен там, где схема или данные какое-то время несогласованы.

Если продажу надо остановить надолго (неполадки у станции, решение
заказчика), режим обслуживания не подходит — для этого есть выключатель
в АИС: **Настройки → Продажа билетов на сайте**. Сайт при этом работает
целиком, покупатель видит объявление и предложение купить в кассе.

## Разовые команды после деплоя

Сид содержимого блоков сайта («виджеты» из legacy) — **один раз** после
первого деплоя новостей и блоков; повторный запуск безопасен, ничего не
перезатирает:

```bash
docker compose exec app php artisan db:seed --class=SiteBlockSeeder --force
```

## Деплой через панель

Панель — `https://deploy.orelavtotrans.ru`, проект «Автовокзал». Устройство
панели (контроллер + исполнитель) — в проекте `deployer`. Деплой-скрипт:
`/usr/local/bin/updateAvtovokzalFromGit.sh`.

Шаги одного прогона: `git reset --hard` → `git pull` → `chown` на 1000 →
сборка фронтов (Nuxt build + Vite build) → `docker compose up -d` →
`docker compose restart frontend ais_spa` (иначе свежая сборка не подхватится)
→ `docker compose restart scheduler` (иначе не перечитаются задания ofelia;
обе ловушки — см. выше) → `composer install` → `php artisan migrate --force`.

`composer install` и `migrate` выполняются **только по флагам** и сразу во
всех четырёх php-сервисах (`app`, `sales_app`, `kkm_app`, `ais_app`):

```bash
/usr/local/bin/updateAvtovokzalFromGit.sh -s -composerInstall -migrate
```

Деплой с новой зависимостью или новой миграцией без этих флагов пройдёт
«успешно» и оставит прод на старом составе vendor и старой схеме.

**ЛОВУШКА: nginx держит IP php-апстрима с момента своего старта.**
`fastcgi_pass ais_app:9000` разрешается ОДИН раз при загрузке конфига. Если
php-контейнер пересоздан (`up -d` при смене его конфига), он получает новый
адрес, а прежний достаётся соседнему контейнеру — и nginx молча проксирует
запросы **в другой сервис**. Ловилось 14.11.2026 на деплое авторизации АИС:
`GET /api/v1/reports` отвечал `404 The route api/v1/reports could not be
found` — потому что `ais_web` ходил в php-fpm backend'а сайта, где такого
маршрута нет (а `/api/v1/auth/me` там есть и падал 500 на
`Route [login] not defined`). При этом `php artisan route:list` в самом
`ais_app` показывал маршрут на месте — отсюда и сбивающая с толку картина.
Лечится рестартом nginx-контейнеров, он дописан в деплой-скрипт (бэкап
`updateAvtovokzalFromGit.sh.bak_20261114_nginx`):

```bash
docker compose restart web ais_web sales_web kkm_web
```

Доступ к приватному репозиторию GitHub — по **deploy-ключу** сервера
(`/root/.ssh/avtovokzal_deploy`, host-alias `github-avtovokzal`; `origin`
указывает на него). Ключ добавлен в репозиторий как read-only Deploy Key.

## Доступ к АИС

**Ограничение по IP снято 14.11.2026** (вместе с появлением авторизации
сотрудников): `ais.orelavtotrans.ru` открыт из интернета, вход закрывает
логин с паролем. Прежний блок в vhost'е
(`allow 192.168.0.0/16; allow 127.0.0.1; deny all;`) убран, бэкап конфига —
`/etc/nginx/sites-available/ais.orelavtotrans.ru.conf.bak_20261114`.

Почему так: ограничение по IP было единственной защитой АИС (авторизации
в legacy не было вовсе), и оно же мешало работать — из-за hairpin-NAT
запрос к публичному домену из локальной сети приходил с подменённым
адресом источника (WAN `91.202.165.5`), а не с `192.168.2.x`, и
отбивался; приходилось ходить напрямую на LAN-адрес через строку в hosts.

Что осталось привязано к сети: **токен быстрого входа** (`?token=…`)
действует только из доверенных сетей — `AIS_TOKEN_NETWORKS` в
`ais-api/.env`, по умолчанию приватные диапазоны (`127.0.0.1/32`,
`::1/128`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`). Токен виден
в адресной строке и в журналах прокси, поэтому снаружи он не работает
вообще — снаружи только логин и пароль. Для этого в `ais-api` включён
`trustProxies(at: '*')`: снаружи в АИС попадают только через системный
nginx, а без доверия его `X-Forwarded-For` реальный адрес клиента до
приложения не доходит.

### Первый администратор и сброс пароля

```bash
cd /data/avtovokzal_new_platform
docker compose exec ais_app php artisan ais:admin <логин> [--password=…] [--name=…] [--code=…] [--token]
```

Без `--password` пароль генерируется (парольная фраза) и печатается в
вывод. Команда работает и как сброс: для существующего логина меняет
пароль и гасит все его сессии. Дальше сотрудников создаёт и сбрасывает
любой администратор прямо в АИС («Сотрудники», «Роли и права»).
`--code=` задаёт код подтверждения опасных действий — **без него не
пройдут возврат билета и удаления**; его же можно задать в профиле.

## Часовые пояса

| Где | Пояс |
|---|---|
| Хост сервера | **Europe/Moscow** (переведён с Asia/Omsk 14.11.2026) |
| Контейнеры (`app`, `sales_app`, `kkm_app`, `ais_app`, `scheduler`, `db`) | Europe/Moscow (`TZ` в compose) |
| Сервер PostgreSQL | Europe/Moscow (`command: postgres -c timezone=…`) |
| `app.timezone` и сессия БД у всех четырёх приложений | Europe/Moscow |
| Базы станций (Firebird, напр. «Кромы АС» 192.168.2.5) | +3, московские wall-clock |

**sales-api и kkm-api переведены с UTC на +3 15.11.2026.** У sales-api это
было не только про свои таблицы: времена рейсов BUSTER — московские
wall-clock, а сервис сравнивает их обычными `date()`/`time()` (сегодняшняя
дата в поиске рейсов, отсечка «не ближе 2000 секунд до отправления»), и в
UTC сравнения уезжали на 3 часа. Пояс процесса Laravel берёт из
`config('app.timezone')`, поэтому `TZ` в compose на `date()` НЕ влияет.
Записанное до перевода осталось в UTC — это тестовые прогоны.

Пояс хоста важен не только для журналов: **KkmServer стоит на хосте, а не в
docker**, и время в фискальных чеках берёт у системы — на Asia/Omsk чеки
уходили бы с +6. После смены пояса службу нужно перезапустить
(`systemctl restart kkmserver`).

**ЛОВУШКА пояса БД.** Eloquent пишет даты строкой БЕЗ смещения, поэтому
значение трактуется по поясу СЕССИИ. Каждое приложение задаёт его явно в
своём `config/database.php`; если какой-то сервис положится на умолчание
сервера, при следующей смене пояса сервера все его timestamptz уедут на 3
часа. Проверка — `show timezone` из контейнера сервиса.

## Данные

Postgres (`data/postgres`, PGDATA uid 999) и Firebird (`databases/*.fdb`)
перенесены с dev **как есть** (bind-mount, в git не хранятся). В одном Postgres
живут четыре базы: `avtovokzal`, `sales`, `kkm`, `ais`. История покупок
(`orders`/`tickets`/`refunds`) уже в Postgres — отдельный прогон
`legacy:import-site` для прод-данных не требуется.

## Адрес сайта — одна переменная

Публичный адрес прода задаётся **один раз** — `SITE_URL` в корневом `.env`
рабочей копии:

```
SITE_URL=https://автовокзал57.рф
```

Адрес пишется **кириллицей**: он попадает в билет (ссылка возврата по коду)
и в SEO-разметку, а punycode там выглядит как подделка. Исключение —
адреса, которые внешняя система сверяет байт в байт: `SBERBANK_RETURN_URL`
и `VK_REDIRECT_URI` задаются в `backend/.env` **в punycode**
(`https://xn--57-6kcaja9axlzb9b.xn--p1ai/...`).

Compose раздаёт его сервисам: `FRONTEND_URL` (backend — ссылки писем, адрес
возврата платёжного шлюза, `redirect_uri` VK), `SITE_FRONTEND_URL` (ais-api —
ссылки печати в отчётах), `NUXT_PUBLIC_SITE_URL` и `NUXT_PUBLIC_API_BASE`
(Nuxt), `VITE_SITE_URL` (ais-spa).

Два следствия:

- **переменная окружения контейнера перекрывает `backend/.env`** (Dotenv не
  перезаписывает уже заданные переменные) — поэтому `FRONTEND_URL` правится в
  `SITE_URL`, а не в `backend/.env`;
- **после правки нужен `docker compose up -d`**, а не `restart`: окружение
  задаётся при создании контейнера.

`PRINT_BASE_URL` (`http://frontend:3000`) к этому не относится — это
внутренний адрес, по которому Gotenberg открывает печатную форму.

## Платёжный шлюз (эквайринг)

Реквизиты живут только в `backend/.env` (`config/services.php` → `sberbank`),
в git их нет:

```
SBERBANK_API_URI=https://3dsec-payments.yookassa.ru      # ТОЛЬКО схема+хост
SBERBANK_USERNAME=<shopId>
SBERBANK_PASSWORD=<секрет>
SBERBANK_RETURN_URL=https://xn--57-6kcaja9axlzb9b.xn--p1ai/payment/result   # punycode
```

**Ловушка `SBERBANK_API_URI` — без пути.** SDK
`voronkovich/sberbank-acquiring-client` сам добавляет префикс
(`API_PREFIX_DEFAULT = '/payment/rest/'`, `Client::execute()`:
`$uri = $apiUri . $action`). Адрес с `/payment/rest/` на конце превращается в
`…/payment/rest//payment/rest/register.do`, шлюз отвечает **404**, и заказ не
создаётся: в `storage/logs/laravel.log` — `Bad HTTP code: 404` из
`PaymentGateway->register`. Боевые адреса: `https://payments.yookassa.ru`
(ЮKassa), `https://securepayments.sberbank.ru` (Сбербанк); тестовые —
`https://3dsec-payments.yookassa.ru` и `https://3dsec.sberbank.ru`.

**`SBERBANK_RETURN_URL` обязан быть публичным адресом сайта.** На него шлюз
возвращает браузер покупателя после 3-DS, и уже страница `/payment/result`
дёргает подтверждение оплаты. С `http://127.0.0.1:3000/...` разработчик у себя
«возврат» увидит (у него на :3000 свой фронт), а покупатель — нет: оплата
останется неподтверждённой.

Сертификаты обоих шлюзов выпущены российским УЦ, поэтому HTTP-клиенту отдаётся
свой бандл — `backend/resources/certificates/russian_trusted_root_ca.pem`
(`services.sberbank.ca_bundle`), только для этого клиента. Проверить доступность
шлюза с сервера: `curl --cacert <бандл> -d 'userName=x&password=y' <api_uri>/payment/rest/register.do`
— `errorCode 5 Access denied` означает, что путь и TLS в порядке, дело в кредах.

После правки `.env` — `docker compose exec app php artisan config:clear`
(кеш конфига на проде не собран, но очистка не лишняя; php-fpm читает `.env`
на каждый запрос, перезапуск контейнера не нужен).

## Почта (SMTP)

Письма backend отдаёт **Exim в соседнем контейнере** `smtp-exim` (проект
`/data/smtp`, `network_mode: host`, слушает `192.168.2.58:25`, SMTP AUTH нет —
релей разрешён с любого адреса, наружу порт не проброшен). `backend/.env`:

```
MAIL_MAILER=smtp
MAIL_URL="smtp://192.168.2.58:25?verify_peer=0"
MAIL_FROM_ADDRESS="site@orelavtotrans.ru"
MAIL_FROM_NAME="автовокзал57.рф"
```

Адрес, имя отправителя и сам SMTP-хост взяты из legacy
(`avtovokzal57/inc/smtp.php`: активная секция — `192.168.2.58:25`,
`From: "автовокзал57.рф" <site@orelavtotrans.ru>`), чтобы покупатель видел
прежнего отправителя. Закомментированные там же SendPulse-ветки (`smtp-pulse.com`,
логин `v.turin@`) — прошлые варианты, не переносим.

- адрес именно `192.168.2.58` — на `127.0.0.1` Exim не слушает, а контейнеру
  `app` хост виден по LAN-адресу (его собственный docker-bridge `172.18.0.1`
  тоже не подойдёт);
- **`verify_peer=0` обязателен**: Exim предлагает STARTTLS с самоподписанным
  сертификатом, и Symfony Mailer по умолчанию рвёт рукопожатие на проверке
  (`gnutls_handshake: Decryption has failed` в `/data/smtp/log/mainlog`).
  Шифрование при этом остаётся (в журнале `P=esmtps X=TLS1.3 … CV=no`);
- параметры передаются через `MAIL_URL`, а не отдельными `MAIL_HOST`/`MAIL_PORT`,
  потому что опции TLS Laravel пробрасывает в DSN только из строки подключения
  (`ConfigurationUrlParser` → `EsmtpTransportFactory`); так настройка живёт в
  `.env` и не требует правки `config/mail.php`.

Проверка доставки — `tail -f /data/smtp/log/mainlog`, очередь —
`cd /data/smtp && docker compose exec smtp exim4 -bp`.

Домен `orelavtotrans.ru` для самого Exim **не локальный**: письма на
`@orelavtotrans.ru` он отправляет наружу, на MX Яндекса — то есть в рабочие
ящики. Тестовые письма шлите на свой адрес, а не на `support@`/`site@`.

Письма кабинета (сброс пароля) уходят синхронно и уже работают. Письмо о
покупке, приглашение в кабинет и уведомление об обращении складываются в
database-очередь `emails`, а её **воркер на проде не запущен** — см. «Что
доделать».

### Настройки Exim, которые пришлось поправить (14.11.2026)

Правятся **только** в `/data/smtp/config/exim4.conf.localmacros`
(бэкап рядом, `*.bak_20261114`), после чего `docker compose restart smtp`:

```
disable_ipv6 = true
MAIN_IGNORE_BOUNCE_ERRORS_AFTER = 1h
MAIN_TIMEOUT_FROZEN_AFTER = 2d
```

- **`disable_ipv6`** — у сервера нет IPv6-маршрута, а Exim пробует AAAA-адреса
  MX первыми и ждёт таймаут `Network is unreachable` на каждом хосте; в
  retry-базе от этого копились записи `T:`;
- **bounce'ы не держим неделю.** Отчёты о недоставке уходят на
  `site@orelavtotrans.ru`, а Яндекс отбивает их как спам
  (`554 5.7.1 Message rejected under suspicion of SPAM`) — такие письма
  замораживались и копились (было 88 штук). Теперь неудачный bounce
  удаляется через час, любое зависшее письмо — через двое суток.

**ЛОВУШКА:** эти два тайм-аута задаются ТОЛЬКО через макросы
`MAIN_IGNORE_BOUNCE_ERRORS_AFTER` / `MAIN_TIMEOUT_FROZEN_AFTER` (шаблон
подставляет их в строках 274/279 `exim4.conf.template`). Если написать в
localmacros сами опции (`ignore_bounce_errors_after = 1h`), конфиг получится
с двумя одинаковыми строками, Exim откажется стартовать
(`option set for the second time`) и контейнер уйдёт в restart-петлю — почта
перестанет приниматься до откатa.

Остаток очереди — письма на переполненные gmail-ящики
(`452-4.2.2 The recipient's inbox is out of storage space`) и адреса,
которых Gmail временно лимитирует (`450-4.2.1`). С нашей стороны тут делать
нечего: Exim повторяет попытки и затем отбивает письмо отправителю.
Принудительный прогон очереди, игнорируя retry-времена, —
`docker compose exec smtp exim4 -qff`; удалить письмо — `exim4 -Mrm <id>`.

## Что доделать

- **Воркер очереди `emails`** на проде не запущен: письма о покупке,
  приглашения в кабинет и уведомления об обращениях копятся в таблице `jobs`
  (накопленные за тестовые прогоны 13.11.2026 удалены). Включать — тем же
  способом, что ночной Z-отчёт:
  job-exec в ofelia (`docker/ofelia/config.ini`) с
  `php artisan queue:work --queue=emails --stop-when-empty`.
- При желании — убрать `api.fontshare.com` из провайдеров `@nuxt/fonts`, чтобы
  сборка не ждала таймаут.

## KkmServer (фискализация, ККМ)

Фискальные чеки `kkm-api` пробивает через **KkmServer** (kkmserver.ru) — HTTP-мост
к ККТ. Он стоит **на хосте прода (`192.168.2.55`), не в docker** (устройство ККТ подключается к
хосту физически), слушает `5893`; `kkm-api` ходит в него по
`http://host.docker.internal:5893` (`KKM_SERVER_URL`).

**Установка:** пакет `KkmServer.deb` с kkmserver.ru → `/opt/kkmserver`.

**Неочевидное — почему не работает «из коробки» на сервере.** Linux-сборка
KkmServer это десктопное приложение Avalonia/Skia:

- штатный режим службы (`kkmserver -d`) на headless-сервере **падает** —
  Skia не может инициализировать графику (`SkiaSharp.SKImageInfo ... threw an
  exception`);
- консольный режим `-s` **требует TTY** (ждёт `x` для выхода);
- при **сохранении настроек** KkmServer перезапускает сам себя, и без дисплея
  этот перезапуск тоже падает.

**Решение — запускать под виртуальным дисплеем + псевдотерминалом.** Поставлены
`xvfb` + графические библиотеки (`libgl1`, `libfontconfig1`, X-библиотеки,
шрифты). systemd-юнит `/etc/systemd/system/kkmserver.service`:

```
ExecStart=/usr/bin/xvfb-run -a /usr/bin/script -qefc '/opt/kkmserver/kkmserver -s' /dev/null
Restart=always
```

(`xvfb-run` даёт дисплей, `script` — псевдотерминал). Служба `enabled`
(автозапуск после ребута), слушает `*:5893`.

**Авторизация и устройство.** В KkmServer заведён аккаунт `User` с паролем;
в `kkm-api/.env` заданы `KKM_SERVER_USER=User`, `KKM_SERVER_PASSWORD=...`,
`KKM_NUM_DEVICE=1`. Устройство 1 — сейчас **виртуальная ККТ** (эмулятор, демо-ФН).
Нюанс: на команды `/Execute` KkmServer авторизацию строго не навязывает
(принимает и неверный пароль) — для LAN-контура это приемлемо; при желании
включается в его настройках.

**Боевая фискализация — VikiPrint.** Планируется физический регистратор
**VikiPrint** по USB: на хосте появится как `/dev/ttyACM0` (драйвер `cdc_acm` в
ядре есть), KkmServer (от root) с ним работает напрямую. Нужны: подключить
принтер к серверу, настроить устройство в KkmServer (веб-страница
`http://192.168.2.55:5893` из LAN), зарегистрировать ФН/ОФД, лицензия.
`EmulationCheck` при этом выключается.

**Безопасность.** `5893` слушается на всех интерфейсах (LAN), наружу **не
пробрасывать**. Файлы: `/opt/kkmserver`, служба
`/etc/systemd/system/kkmserver.service`.

## Где лежит серверная инфраструктура (вне этого репозитория)

- vhost'ы nginx: `/etc/nginx/sites-available/`, сниппеты `/etc/nginx/snippets/`;
- панель деплоя: `/data/deployer`, конфиги `/etc/gotov-deploy/`;
- деплой-скрипт: `/usr/local/bin/updateAvtovokzalFromGit.sh`;
- сертификаты: `/etc/letsencrypt/`;
- KkmServer: `/opt/kkmserver`, служба `/etc/systemd/system/kkmserver.service`.
