# Деплой и прод-окружение (orelavtotrans)

Как проект развёрнут в проде и как его обновлять. Прод стоит на отдельном
сервере в локальной сети (`192.168.0.75`), наружу доступен по доменам через
проброс портов 80/443 на роутере. Публичный IP — `109.61.151.34`.

## Домены и маршрутизация

Единая точка входа снаружи — **системный nginx на хосте** (не в docker). Он
терминирует TLS и проксирует на сервисы, слушающие только на `127.0.0.1`.
Внутренние сервисы (Postgres, Firebird, gotenberg) наружу не публикуются.

| Домен | Назначение | Куда проксируется |
|---|---|---|
| `site.orelavtotrans.ru` | Сайт | `/` → frontend (3000), `/api/v1/` → web (8088) |
| `ais.orelavtotrans.ru` | Админка/отчёты АИС | `/` → ais_spa (3001), `/api/` → ais_web (8086), `/site-api/` → web (8088); **только из LAN** |
| `deploy.orelavtotrans.ru` | Панель деплоя | `/` → контроллер (8099), `/agent` → php-fpm (исполнитель) |

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
```

`.output`/`dist` живут в bind-mount, поэтому после перезагрузки контейнеры
стартуют сразу готовой сборкой, без пересборки.

Нюанс сети: `@nuxt/fonts` на сборке тянет шрифты; `api.fontshare.com` с сервера
недоступен (нефатальный таймаут ~24с), остальные шрифты качаются с
`fonts.gstatic.com` — сборка проходит.

### Владелец файлов

`git pull` на деплое выполняется от **root**, а контейнеры работают под uid
`1000` — поэтому деплой-скрипт после pull делает `chown -R 1000:1000` на кодовые
каталоги, иначе `npm`/`composer` в контейнерах падают с `EACCES`.

## Деплой через панель

Панель — `https://deploy.orelavtotrans.ru`, проект «Автовокзал». Устройство
панели (контроллер + исполнитель) — в проекте `deployer`. Деплой-скрипт:
`/usr/local/bin/updateAvtovokzalFromGit.sh`.

Шаги одного прогона: `git reset --hard` → `git pull` → `chown` на 1000 →
сборка фронтов (Nuxt build + Vite build) → `docker compose up -d` →
`composer install` → `php artisan migrate --force`.

Доступ к приватному репозиторию GitHub — по **deploy-ключу** сервера
(`/root/.ssh/avtovokzal_deploy`, host-alias `github-avtovokzal`; `origin`
указывает на него). Ключ добавлен в репозиторий как read-only Deploy Key.

## Доступ к АИС только из LAN

`ais.orelavtotrans.ru` закрыт на уровне nginx: `allow 192.168.0.0/16; deny all;`.

Из-за hairpin-NAT запрос к публичному домену из локальной сети приходит на
сервер с подменённым адресом источника (WAN `109.61.151.34`), а не с реального
`192.168.0.x` — и отбивается. Чтобы открыть АИС из LAN, нужно ходить **напрямую
на LAN-адрес** сервера, минуя публичный DNS:

- разово на машине — строка в hosts: `192.168.0.75  ais.orelavtotrans.ru`;
- на всю сеть — локальный DNS (роутер или dnsmasq): `ais.orelavtotrans.ru → 192.168.0.75`.

TLS-сертификат при этом совпадает (имя то же), предупреждений нет.

## Данные

Postgres (`data/postgres`, PGDATA uid 999) и Firebird (`databases/*.fdb`)
перенесены с dev **как есть** (bind-mount, в git не хранятся). В одном Postgres
живут четыре базы: `avtovokzal`, `sales`, `kkm`, `ais`. История покупок
(`orders`/`tickets`/`refunds`) уже в Postgres — отдельный прогон
`legacy:import-site` для прод-данных не требуется.

## Что доделать

- **Backend в прод-режим по env** (`backend/.env`): `APP_ENV=production`,
  `APP_DEBUG=false`, `APP_URL=https://site.orelavtotrans.ru`; затем
  `docker compose exec app php artisan config:clear`. Сейчас ещё `local`/`debug`.
- При желании — убрать `api.fontshare.com` из провайдеров `@nuxt/fonts`, чтобы
  сборка не ждала таймаут.

## KkmServer (фискализация, ККМ)

Фискальные чеки `kkm-api` пробивает через **KkmServer** (kkmserver.ru) — HTTP-мост
к ККТ. Он стоит **на хосте `.75`, не в docker** (устройство ККТ подключается к
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
принтер к `.75`, настроить устройство в KkmServer (веб-страница
`http://192.168.0.75:5893` из LAN), зарегистрировать ФН/ОФД, лицензия.
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
