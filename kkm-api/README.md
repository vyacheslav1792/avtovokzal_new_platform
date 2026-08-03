# kkm-api — KKM API

Сервис-прослойка между платформой и **KkmServer** (https://kkmserver.ru) для регистрации
фискальных чеков. Laravel 13, порт :8087 (`kkm_web` → `kkm_app`), БД Postgres `kkm`.

KkmServer установлен на хост-машине (systemd `kkmserver.service`, :5893, виртуальная
ККТ NumDevice=1); из контейнера доступен как `host.docker.internal:5893`.

## API

Аутентификация: `Authorization: Bearer <токен>` (`config/clients.php`) + IP whitelist.
Все запросы/ответы журналируются в `api_requests`.

- `POST /api/v1/receipts` — регистрация чека:
  ```json
  {
    "external_id": "sale:<uuid>",      // ключ идемпотентности клиента
    "type": "sale",                    // sale | refund (приход / возврат прихода)
    "email": "buyer@example.com",
    "electronic_payment": 506.75,      // должна сходиться с суммой позиций до копейки
    "items": [{"name": "Билет …", "price": 435.95, "quantity": 1}]
  }
  ```
  Ответ: `status` (`registered|pending|error`), `check_number`, `fiscal_sign` (ФП из
  QR-кода), `session_number`, `qr_code`, `error`.
- `GET /api/v1/receipts/{external_id}` — статус чека (для `pending` уточняет результат
  в KkmServer по IdCommand).

## Идемпотентность (два уровня)

1. **`external_id`** — уникален в таблице `receipts`: повторный запрос возвращает уже
   пробитый чек, второго чека не будет. Если предыдущая попытка легла в `error`,
   повтор обновляет payload (клиент мог исправить суммы) и переотправляет.
2. **`IdCommand`** — та же операция внутри KkmServer: перед любой повторной отправкой
   сервис спрашивает `GetRezult` и переотправляет только с тем же IdCommand.
   Ловушка: у ответа `GetRezult` внешний `Status` — статус самого GetRezult,
   результат исходной команды вложен в `Rezult` со своим `Status`.

## Смена ККТ (54-ФЗ: не более 24 часов)

- **Реактивно**: если ККТ отклонила чек из-за истёкшей смены (текст ошибки или
  `GetDataKKT` → `Info.SessionState = 3`), сервис пробивает Z-отчёт (`CloseShift`)
  и повторяет чек. Новая смена открывается автоматически первым чеком.
- **По расписанию**: ночной Z-отчёт в 23:59 МСК — `php artisan kkm:close-shift`,
  запускается планировщиком **ofelia** (сервис `scheduler` в docker-compose,
  задание в `docker/ofelia/config.ini`, job-exec в контейнер `avtovokzal-kkm-app`).
  Команда ничего не делает, если смена уже закрыта.

## Конфигурация

`config/kkm.php` (env): `KKM_SERVER_URL`, `KKM_SERVER_USER`/`KKM_SERVER_PASSWORD`,
`KKM_NUM_DEVICE`, `KKM_COMMAND_TIMEOUT`, `KKM_CASHIER_NAME`.
Клиентские токены: `config/clients.php` (`KKM_CLIENT_BACKEND_TOKEN`).
