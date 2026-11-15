#!/usr/bin/env bash
#
# Суточный бэкап баз PostgreSQL новой платформы: сайт (avtovokzal), АИС
# (ais), API продаж (sales) и KKM API (kkm) + роли сервера.
#
# Один прогон = один zip-архив со всеми дампами и манифестом. Хранится
# ограниченное число последних копий, старые удаляются.
#
# Запускается systemd-таймером в 02:00 по времени сервера (Europe/Moscow);
# установка и расписание описаны в docs/DEPLOY.md. Пароль не нужен: внутри
# контейнера db подключение локальное.
#
# Параметры (можно переопределить переменными окружения):
#   PROJECT_DIR — рабочая копия проекта с docker-compose
#   BACKUP_DIR  — куда класть архивы
#   KEEP        — сколько последних копий хранить
#   DATABASES   — список баз через пробел
set -euo pipefail

PROJECT_DIR=${PROJECT_DIR:-/data/avtovokzal_new_platform}
BACKUP_DIR=${BACKUP_DIR:-/mnt/raid1/backups}
KEEP=${KEEP:-10}
DATABASES=${DATABASES:-"avtovokzal ais sales kkm"}

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die() { log "ОШИБКА: $*"; exit 1; }

# Два прогона разом не нужны: дамп сайта идёт минуты, а таймер может
# наложиться на ручной запуск.
exec 9>/var/lock/avtovokzal-db-backup.lock
flock -n 9 || { log "Бэкап уже выполняется — выходим"; exit 0; }

[ -d "$PROJECT_DIR" ] || die "нет каталога проекта $PROJECT_DIR"
[ -d "$BACKUP_DIR" ] || die "нет каталога для бэкапов $BACKUP_DIR"

cd "$PROJECT_DIR"

# Имя пользователя БД спрашиваем у самого контейнера: так бэкап не
# отвалится после смены реквизитов, а `.env` проекта подключать целиком
# нельзя — там есть UID, а он в bash только для чтения (`. ./.env` падает).
PG_USER=$(docker compose exec -T db printenv POSTGRES_USER | tr -d '\r')

if [ -z "$PG_USER" ]; then
    PG_USER=$(grep -E '^POSTGRES_USER=' ./.env | tail -1 | cut -d= -f2- | tr -d '"'\''')
fi

[ -n "$PG_USER" ] || die "не удалось определить POSTGRES_USER"

stamp=$(date '+%Y-%m-%d_%H%M')
work=$(mktemp -d "${BACKUP_DIR}/.tmp-${stamp}-XXXXXX")
trap 'rm -rf "$work"' EXIT

dc() { docker compose exec -T db "$@"; }

log "Бэкап начат: базы [$DATABASES], каталог $BACKUP_DIR"

{
    echo "Бэкап баз PostgreSQL новой платформы (avtovokzal_new_platform)"
    echo "Снято:        $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "Сервер БД:    $(dc psql -U "$PG_USER" -d postgres -tAc 'select version()' | tr -d '\r')"
    echo "Базы:         $DATABASES"
    echo
} > "$work/manifest.txt"

# Роли и права сервера: без них восстановление базы начинается с вопроса
# «а кто её владелец».
dc pg_dumpall -U "$PG_USER" --globals-only > "$work/globals.sql"
[ -s "$work/globals.sql" ] || die "пустой дамп ролей"

for db in $DATABASES; do
    log "Дамп базы $db…"
    dc pg_dump -U "$PG_USER" --format=plain --no-owner --no-privileges "$db" > "$work/$db.sql"

    # pg_dump при обрыве оставляет ОБРЕЗАННЫЙ файл, а код возврата теряется
    # в конвейере — поэтому проверяем завершающую строку самого дампа.
    tail -c 200 "$work/$db.sql" | grep -q 'PostgreSQL database dump complete' \
        || die "дамп базы $db не завершён (файл обрезан)"

    printf '%-16s %s\n' "$db" "$(du -h "$work/$db.sql" | cut -f1)" >> "$work/manifest.txt"
done

archive="$BACKUP_DIR/avtovokzal-db-${stamp}.zip"

log "Упаковка в $archive…"
(cd "$work" && zip -q -9 "$archive" manifest.txt ./*.sql)
zip -T "$archive" > /dev/null || die "архив не прошёл проверку целостности"

log "Готово: $archive ($(du -h "$archive" | cut -f1))"

# Ротация: оставляем KEEP последних архивов.
mapfile -t stale < <(ls -1t "$BACKUP_DIR"/avtovokzal-db-*.zip 2>/dev/null | tail -n +$((KEEP + 1)))

for f in "${stale[@]:-}"; do
    [ -n "$f" ] || continue
    log "Удаляю старую копию: $(basename "$f")"
    rm -f -- "$f"
done

log "Копий в каталоге: $(ls -1 "$BACKUP_DIR"/avtovokzal-db-*.zip 2>/dev/null | wc -l) (храним $KEEP)"
