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

Порты: web :8088 (backend, высокоуровневое API avtovokzal57; **был :8080** —
переехали 2026-08-14, на хосте его постоянно держит vite соседнего проекта,
и контейнер `web` не поднимался), sales_web :8085
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
  литерала (`BusterConnection::quoteTimestamp`). `GET /trips` без `date` — режим
  «ближайшие» legacy (`blij=1`): рейсы всех дат от сегодняшней, `first 200`;
  `for_sale=0` отдаёт и рейсы, недоступные к интернет-продаже. Ограничения
  продажи по дальности (`min_kilom`/`max_kilom`) считаются после выборки по
  TRACES — как в legacy; ручное перекрытие SALE_BOUND_AFTER (legacy-файл
  `vidjets/ogranichenie.txt`) переехало в `config/sale_bounds.php`.
  **Список пунктов назначения (`GET /stations/{awtcod}/destinations`) сортируется
  в PHP** (`App\Support\StationNameSorter`), а НЕ в запросе: KS_STATIONS отдаёт
  порядок базы — с учётом регистра и знаков, поэтому «АЗС», «СХТ», «ДРСУ-3»
  вставали в начало группы, а «пов.Вепринец», «м-н Москва», «ул. Заводская» —
  в самый конец. Ключ сортировки: нижний регистр, ё→е, всё кроме букв и цифр —
  разделитель, лишние пробелы убраны; сравнение Collator `ru_RU` (без ext-intl
  фолбэк на побайтовое — после нормализации порядок тот же). Та же логика
  добавлена в legacy (`ais/api/sort_help.php` + `api2.php`, выложено на бой
  2026-08-14 с бэкапами `*.bak_20260814`), порядок сверен построчно на боевых
  списках. Селекты сайта менять не пришлось: Nuxt UI при вводе поднимает
  совпадения с начала названия и сохраняет порядок сервера внутри групп; в
  legacy для этого понадобилась своя `score` у selectize (`js/main.js`), иначе
  найденное сортировалось по релевантности — короткие названия вперёд.
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
  legacy), `/trips` (**объединяет legacy-поиск билетов и legacy-расписание до
  остановки**: переключатель `?all=1` показывает рейсы без интернет-продажи со
  статусами legacy — «Продажа в кассах», «Транзитный», «Нет свободных мест»,
  «Отменён», «Сорван», «Ушёл», «Продажа ограничена … не ближе/не дальше чем до»;
  без `?date` — режим «ближайшие», legacy `blij=1`),
  **`/buy?awtcod=&to=&date=&napcod=&raicecod=`** (рейс задан адресом, а не
  состоянием — ссылкой на покупку можно делиться; данные рейса и мест НЕ
  кэшируются: `getCachedData` отдаёт payload только при гидрации, а страница
  идёт с `Cache-Control: no-store` — `routeRules` в `nuxt.config.ts`; если
  купить нельзя, страница называет причину по правилам legacy),
  `/payment/result`,
  `/backbycode` (возврат по коду, текст
  legacy 1:1, принимает `?code=`), `/print/[id]` (печатные формы A4: билет + багажные
  квитанции; у ПОСЛЕДНЕГО бланка `page-break-after: auto` — иначе печать/PDF
  добавляют пустую страницу). PDF билетов рендерит **gotenberg** (сервис в compose,
  Chromium за HTTP API) по URL этой же страницы — как legacy `pdf.php` гнал
  `print.php` через wkhtmltopdf; кэш файла на диске не держим, в отличие от legacy.
  Наружу gotenberg не публикуем, ходит по внутренней сети (`--chromium-allow-list`).
  **ЛОВУШКА dev-режима:** gotenberg открывает страницу с `Host: frontend`, а Vite
  такие хосты блокирует (403 «This host is not allowed») — держим
  `vite.server.allowedHosts: ['frontend']` в `nuxt.config.ts`, иначе PDF
  перестаёт собираться.
  Кнопка «Версия для печати» вызывает функцию компонента (`printPage`), а НЕ
  `print()` прямо из шаблона: в выражениях шаблона Vue видит лишь свойства
  компонента и свой список глобалов, где `print` отсутствует — кнопка молча
  не работает. Печатные стили страниц `/tarif` и `/raspisanie-avtobusov`
  вписывают таблицу в лист (без горизонтальной прокрутки) и печатают ссылки
  чёрным; проверять удобно тем же gotenberg с `emulatedMediaType=print`.
  Ссылки ВНУТРЬ печатных форм (возврат по коду) берём из
  `runtimeConfig.public.siteUrl` (`NUXT_PUBLIC_SITE_URL`), а НЕ из
  `useRequestURL()`: при рендере PDF origin равен внутреннему
  `http://frontend:3000` и такой адрес попадает на бумагу.
  «Популярные направления» на главной фильтруются по станциям из конфига —
  плитки недоступных станций скрываются сами. SSR ходит в backend по `http://web`
  (runtimeConfig.apiBaseServer), браузер — на :8088.
- **Личный кабинет покупателя** (перенос legacy `url/lk.php`, `login.php`,
  `register.php`, `forgot.php`, `newpass.php`, `change.php`, `ticketback.php`):
  backend `auth/*` + `cabinet/*`, фронт `/lk`, `/lk/profile`, `/login`,
  `/register`, `/forgot`, `/reset`. Авторизация — **Bearer-токен Sanctum**
  (им же будет ходить мобильное приложение). Токен хранится в **httpOnly
  cookie `av_session`, которую ставит сервер Nuxt** (`frontend/server/api/auth/
  session.{get,post,delete}.ts`): cookie и localStorage, записанные из JS,
  Safari/ITP стирает через ~неделю — покупатель разлогинивался бы. Портал
  получает токен из `GET /api/auth/session` (SSR — с cookie запроса) и шлёт
  в backend как Bearer. Менять cookie можно только same-origin запросом
  (`assertSameOrigin`) — иначе чужой сайт подсунул бы свой токен.
  **Срок — полгода бездействия с обеих сторон:** `maxAge` cookie обновляется
  при каждом чтении сессии, а токен проверяется по `last_used_at`
  (`Sanctum::authenticateAccessTokensUsing` в AppServiceProvider,
  `site.token_idle_minutes`; `expiration` Sanctum не годится — он считает
  от created_at). Мёртвые токены и старые ссылки писем чистит
  `auth:prune-tokens` (ofelia, ежедневно в 04:30).
  Схема сразу под расширение (вход по SMS): способы входа —
  строки `user_identities` (`password` | `vk` | `phone`), пароль в `users`
  **nullable**. Одноразовые ссылки писем (приглашение, сброс, подтверждение
  e-mail) — `auth_tokens`
  (в базе только sha256, TTL, отметка использования); legacy хранил пароль
  открытым текстом в `USERS.PASSWORD_RAW` — **не повторять**.
  Пароль: `trim` при задании И при проверке, минимум 8 символов, сложность
  сознательно не проверяем. Заказы кабинета ищутся по `user_id` **или**
  `orders.email` — покупки «на этот e-mail» до регистрации считаются своими.
  У авторизованного покупателя e-mail заказа берётся из профиля (поле на форме
  покупки заблокировано), телефон редактируется. Письма кабинета
  (сброс пароля) шлются **синхронно**, приглашение после первой покупки —
  джобом в очередь `emails` (воркер писем по-прежнему не запущен).
- **Вход через VK ID** (в legacy входа через соцсети не было — новая
  возможность): OAuth 2.1 + PKCE **без JS-SDK**. Кнопка — обычная ссылка на
  серверный маршрут Nuxt `/auth/vk`, тот спрашивает у backend адрес
  авторизации (`auth/vk/start`, там же генерируются code_verifier и state) и
  уводит на `id.vk.ru/authorize`; возврат ловит `/auth/vk/callback`, код на
  токен меняет backend (`auth/vk/callback` → `VkIdClient`), а Nuxt кладёт
  выданный Sanctum-токен в ту же httpOnly cookie `av_session`. client_id и
  обмен кода фронту не видны. **state одноразовый**: verifier лежит в кэше
  backend (сессии у API нет) и вынимается через `Cache::pull`, плюс дубль
  в httpOnly cookie `vk_state` — сверка защищает от подсунутого чужого кода.
  Токен VK после получения профиля гасим (`/oauth2/logout`) — ходить в VK
  от имени покупателя мы не собираемся. Ключи — `VK_CLIENT_ID` /
  `VK_CLIENT_SECRET` (`config/vk.php`); **пустой client_id = вход выключен**,
  кнопки нет (тестовых приложений VK не выдаёт, как и у SmartCaptcha).
  Адрес возврата собирается ТОЛЬКО из конфига (`VK_REDIRECT_URI`, иначе
  `FRONTEND_URL`), а не из запроса: он обязан совпадать байт в байт в
  настройках приложения VK, в `/authorize` и в `/oauth2/auth`.
  **VK принимает только https и только стандартный порт**, поэтому на
  локальном контуре поднят https-вход: сервис `proxy` (Caddy,
  `docker/caddy/Caddyfile`, порт **443**, самоподписанный `tls internal`),
  сайт открывается как **https://avtovokzal57.local** — имя прописано в
  `/etc/hosts` (127.0.0.1). Он отдаёт под
  ОДНИМ origin и сайт, и `/api/v1/*` — со страницы по https браузер не даст
  обратиться к http-API (mixed content); `useApi` это учитывает
  (`browserApiBase`: при https-странице базой становится origin). Только
  `/api/v1/*`, потому что `/api/auth/session` — собственный маршрут Nuxt.
  В Caddyfile имена сайта заданы явно (`avtovokzal57.local`, `localhost`,
  `127.0.0.1`): для безымянного блока `tls internal` не может выпустить
  сертификат и рвёт рукопожатие. `VK_REDIRECT_URI` на локальном контуре
  указывает на это имя, http://127.0.0.1:3000 продолжает работать как раньше.
  **E-mail от VK может не прийти** (нет у аккаунта или не дали доступ) —
  по решению заказчика такой кабинет заводится всё равно: `users.email`
  **nullable**, почту потом *предлагаем* (страница профиля, редирект
  `/lk/profile?add-email=1` сразу после первого входа), но не требуем.
  Записать введённый адрес сразу нельзя — кабинет показывает покупки по
  `orders.email`, поэтому владение подтверждается ссылкой из письма
  (`auth_tokens` type=`email_confirm`, страница `/confirm-email`); адрес,
  пришедший от VK, считается подтверждённым сразу. Если e-mail от VK совпал
  с существующим кабинетом — VK **привязывается к нему** (второго кабинета
  с теми же покупками не заводим). У кабинета без пароля профиль показывает
  «Задать пароль» (логином будет e-mail, поэтому без адреса пароль задать
  нельзя), а форма покупки не блокирует поле e-mail — брать его неоткуда.
  **Телефон в профиле необязателен** (`PUT auth/profile`, пустое значение
  стирает номер): кабинет из VK может остаться вообще без контактов. При
  регистрации по e-mail и в форме покупки номер по-прежнему обязателен.
  На `/buy` гостю предлагается войти или зарегистрироваться (модалка;
  ссылки несут `?redirect=` на текущий рейс, там же кнопка VK, `/register`
  этот параметр понимает). Шаг **необязательный** — «Продолжить без
  регистрации» запоминается в `sessionStorage` (`buy-auth-prompt-skipped`),
  чтобы окно не выскакивало на каждом рейсе; при недоступном к покупке
  рейсе не показывается вовсе.
- **Сохранённые пассажиры кабинета** (в legacy этого не было): таблица
  **`saved_passengers`** — ФИО, дата рождения, пол, документ (серия/номер
  разложены `PassengerDocument`), гражданство, `last_used_at`. Состав полей
  и лимиты длины — как у билета; ничего сверх того, что и так лежит в
  `tickets`. **Уникальность — (user_id, doc_type, doc_number)**: повторное
  сохранение того же документа обновляет строку, а не плодит дубли.
  Нормализация ФИО общая с заказом (`App\Support\PassengerName`: верхний
  регистр + Ё→Е) — иначе подставленное из профиля имя расходилось бы с тем,
  что уходит в SALE. Валидация при сохранении — тот же
  `KS_CONTROL_DATA_PAS`, что и при покупке (`SavePassengerRequest`):
  в профиль нельзя положить документ, с которым покупка не пройдёт.
  API: `GET|POST cabinet/passengers`, `PUT|DELETE cabinet/passengers/{id}`.
  На форме покупки — селект «Выбрать из профиля» и галочка «Сохранить
  пассажира в профиль» (поле `passengers.*.save_to_profile` в заказе;
  сохраняет `OrderService::rememberPassengers` уже НОРМАЛИЗОВАННЫЕ данные,
  ошибка сохранения покупку не рушит). У неотмеченных лишь обновляется
  `last_used_at` — по нему список сортируется. Гостю ни селект, ни галочка
  не показываются. Управление — страница `/lk/passengers`.
- **Регулярное расписание** (перенос legacy `/raspisanie-avtobusov` + генератора
  `getrasp.php?action=raspisanie` + `action=raspisanie` в `ais/api/api2.php`):
  sales-api `GET /stations/{awtcod}/schedule` отдаёт **данные, а не HTML**
  (legacy собирал таблицу прямо в API и складывал в файлы
  `url/raspisanie/<slug>.html`). Логика графика перенесена 1:1
  (`RegularScheduleBuilder`): REG_FIXED → даты из `reg_fixed_days`; ни одного
  дня недели → рейс не в расписании; все семь → «Каждый день», а с
  REG_INTERVAL — «Каждые N дня» + две ближайшие даты (таблица PERIOD, отсчёт
  от 11.05.2018); иначе — перечень дней. Рейсы группируются по
  «маршрут + график», времена собираются в список; до CHANDATE действует
  OLDNOMKAT. Хранится в Postgres `station_schedules` (строка на станцию,
  routes jsonb), обновляет команда `schedule:sync` (ofelia, 04:15).
  Сайт читает `GET /api/v1/schedule[/{awtcod|slug|название}]`, страница —
  `frontend/app/pages/raspisanie-avtobusov.vue`: без `?from=` — список станций,
  с `?from=<название>` — маршруты станции, селекторы пункта отправления и
  **пункта прибытия** (+ дата и кнопка «Ближайшие»); выбор прибытия ведёт на
  `/trips` с `all=1` — рейсы до остановки со статусами, как в legacy.
  Старые адреса `?from=&to=&date=5+августа+2026|tomorrow|aftertomorrow`
  и `&blij=1` редиректятся (301) туда же; awtcod резолвится по списку станций
  продаж, а не по опубликованному расписанию. Сверено с боевым api2.php
  построчно.
- **Тариф рейса** (перенос legacy `/tarif` + action=tarif):
  sales-api `GET /stations/{awtcod}/tariff?napcod=&raicecod=&variant=`,
  backend `GET /api/v1/tariff` (кэш 10 мин), страница `/tarif` (клик по времени
  в расписании). **ЛОВУШКА тарифных категорий:** у рейса в `raicelst` их две —
  `OLDNOMKAT`/`NEWNOMKAT` с датой смены `CHANDATE`. Пока CHANDATE в будущем,
  действует OLDNOMKAT (по CHANDATE-1 сутки), а NEWNOMKAT вступает в силу с
  CHANDATE; когда CHANDATE прошла — категория одна (NEWNOMKAT). Legacy тащил
  `nomkat` через URL и полагался на вызывающую страницу — у нас категорию
  **всегда выбирает сервер** (`TariffCard`), клиент просит только
  `variant=current|upcoming`; старые ссылки с `nomkat` работают (параметр
  игнорируется). Тексты и колонки страницы сверены с legacy 1:1, включая
  «Внимание! Тариф изменяется с …» и переключатель старый/новый.
  Сверх legacy: название остановки в таблице — ссылка на рейсы до неё
  (`/trips?…&to=<остановка>&all=1`); дата берётся из `?date` страницы тарифа
  (её передаёт `/trips`), иначе сегодняшняя. Название станции отправления для
  такой ссылки backend отдаёт полем `awtname` (кэш тарифа — ключ `tariff:v2:`,
  в нём теперь ответ целиком).
- **Чёрный список e-mail** (перенос legacy `$CHS` из `avtovokzal57/inc/config.php`,
  где он правился в исходнике): таблица **`blocked_emails`**, модель
  `BlockedEmail` с кэшом на 5 мин (сбрасывается при изменении таблицы),
  команда `php artisan emails:block [email] [--reason=] [--remove]`.
  Закрывает **все четыре** входа legacy: покупку (`StoreOrderRequest`,
  проверяется ИТОГОВЫЙ адрес заказа — у авторизованного он из профиля),
  регистрацию, восстановление пароля и приглашение в кабинет.
- **Контакты сайта** (перенос legacy `avtovokzal57/url/contacts.php`, где страница
  была статическим HTML и телефон правился в исходнике): таблицы
  **`contact_details`** (реквизиты: название + тип `text|phone|email|fax` +
  список значений) и **`contact_points`** (остановочные пункты: название,
  время работы, телефоны, адрес, перерывы — все списки строк, jsonb),
  сервис `ContactService` (кэш публичного ответа 5 мин, сбрасывается при
  сохранении), сид `ContactSeeder` из `database/data/contacts.json`.
  API: публичный `GET /api/v1/contacts` (только `is_active`), админский
  `GET|PUT /api/v1/admin/contacts` — **PUT заменяет оба списка целиком**,
  порядок строк = порядок в массиве (`position`), id клиента не значимы.
  Страница сайта — `frontend/app/pages/contacts.vue`, редактор — ais-spa
  `/contacts` (раздел меню «Администрирование», ходит через `/site-api`).
  В legacy те же данные лежали ДВАЖДЫ (таблица для десктопа + карточки для
  мобильных) и успели разойтись — «55:00 – 23:00» у Орла, 6:30 против 6:40
  у Шаблыкино; за источник взята десктопная таблица, обе вёрстки теперь
  рисуются из одних данных. Скрытые пункты legacy (Нарышкино, Знаменское
  были закомментированы) перенесены с `is_active=false`. Кнопка «Написать
  сообщение» ведёт на страницу `/feedback` (см. «Обратная связь»).
- **Обратная связь** (перенос legacy `avtovokzal57/url/contact.php` +
  модалки «Написать сообщение» в подвале `index.php`; форму по решению
  заказчика делали заново, вёрстку legacy не повторяем): таблицы
  **`feedback_messages`** (тема, имя, e-mail, телефон, текст, статус
  `new|in_progress|answered|spam`, причина пометки, IP, UA, `user_id`,
  заметка оператора, `answered_at`) и **`spam_rules`** (правило: `kind`
  `substring|regex|latin_dominates`, `scope` `body|name|email|any`,
  образец, пояснение, активность). В legacy обращение **нигде не
  сохранялось** — уходило письмом на support_email, а сработавший
  антиспам выбрасывал его молча; теперь сообщение всегда в базе, спам лишь
  помечается и виден оператору. Письмо-уведомление — джобом в очередь
  `emails` (воркер писем по-прежнему не запущен), Reply-To — адрес
  отправителя, как в legacy.
  Капча — **Yandex SmartCaptcha** вместо самописной картинки
  `avtovokzal57/captcha.php` (4 цифры в сессии): российский сервис, без
  трансграничной передачи, в отличие от reCAPTCHA/hCaptcha. Ключи:
  `CAPTCHA_SERVER_KEY` (backend, ysc2_…) и `NUXT_PUBLIC_CAPTCHA_KEY`
  (фронт, ysc1_…). **Тестовых ключей у сервиса нет** — без серверного
  ключа капча выключена (`config/captcha.php`), на локальном контуре так
  и работаем; при недоступности сервиса решает `fail_open`.
  Сверх капчи: honeypot, подписанная метка времени открытия формы
  (`form_token`, Crypt), лимит 5 сообщений с IP в час (лимитер
  `feedback`), проверка `blocked_emails`.
  Антиспам-словарь legacy (~60 `preg_match` в исходнике) перенесён 1:1 в
  `spam_rules` (сид `SpamRuleSeeder` из `database/data/spam_rules.json`),
  включая правило «кириллицы должно быть больше латиницы». **ЛОВУШКА
  регулярных выражений:** образец пишется без ограничителей, и слепо
  экранировать `/` нельзя — в legacy-паттерне ссылки слэши уже
  экранированы (`http(s)?:\/\/…`), второе экранирование превращало `\/`
  в обратный слэш и правило переставало ловить ссылки; разделитель
  подбирает `SpamFilter::compile()`. Словарь очень широк — «заказ»,
  «телефон», «услуги» ловят и обычные вопросы пассажиров; такие правила
  выключаются галочкой в админке (в legacy это означало потерю письма).
  API: публичные `GET /api/v1/feedback/form` (темы, признак капчи,
  `form_token`) и `POST /api/v1/feedback`; админские `admin/feedback`
  (список с фильтрами/поиском/пагинацией, карточка, статус и заметка,
  удаление) и `GET|PUT admin/spam-rules` (**PUT заменяет список целиком**).
  Страница сайта — `frontend/app/pages/feedback.vue` (компонент
  `SmartCaptcha.vue` грузит виджет с `smartcaptcha.yandexcloud.net`;
  токен одноразовый — после отказа виджет сбрасывается), редакторы
  ais-spa — `/feedback` и `/spam-rules`.
- **Текстовые страницы, меню и файлы сайта** (перенос legacy
  `avtovokzal57/url/<slug>.php` + списков ссылок и `switch` с `<title>`
  в `index.php`): таблицы **`pages`** (slug, title, meta_title,
  meta_description, content, is_active) и **`menu_items`**
  (area `header|footer`, title, url, group, position, is_active),
  сервис `PageService` (кэш 5 мин двумя ключами `pages:public` и
  `pages:menu`; теги кэша недоступны — драйвер database), сид
  `PageSeeder` из `database/data/pages.json` + `database/data/pages/*.html`.
  Перенесены 9 страниц меню и подвала: payways, reglament-docs, faq,
  station-sogl-pd, station-polit-pd, moshenniki, uslugi, fz-223, corrupt
  (`station-rules` в legacy закомментирован и файла нет — переносить нечего).
  Содержимое лежит **как в legacy, вместе со своими `<style>` и div-обёртками**:
  видимый текст и title/description сверены с боевым сайтом 1:1.
  API: публичные `GET /api/v1/pages/{slug}`, `GET /api/v1/menu`,
  `GET /api/v1/files/{path}`; админские `admin/pages` (CRUD),
  `GET|PUT admin/menu` (**PUT заменяет оба списка целиком**),
  `GET|POST|DELETE admin/uploads`. Сайт — `frontend/app/pages/[slug].vue`
  (последний по приоритету маршрут; выключенная/несуществующая страница —
  404 через `createError`, ошибку из обработчика `useAsyncData` бросать
  нельзя: она осела бы в `error` и страница ушла бы с кодом 200).
  Меню шапки/подвала фронт берёт из `GET /menu` (`useSiteMenu`), пункты
  с одинаковым `group` собираются в выпадающий раздел («Пассажирам»).
  Файлы страниц (PDF, плакаты) — bind mount `./data/uploads` (диск
  `uploads`, в git не идут), прежние адреса `/docs/...` сохранены:
  Nuxt-роут `server/routes/docs/[...path].ts` проксирует их в backend.
  Редакторы в ais-spa: `/pages` (список + редактор) и `/menu`.
  **Визуальный режим — contenteditable внутри iframe** (`HtmlEditor.vue`):
  iframe обязателен, иначе `<style>` из содержимого страниц (`#pers`,
  полосатые блоки FAQ) разъезжается по всей админке; редактор ничего не
  нормализует, legacy-разметка переживает переключение режимов побайтно.
  HTML-режим — CodeMirror 6 с подсветкой (`CodeEditor.vue`).
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
