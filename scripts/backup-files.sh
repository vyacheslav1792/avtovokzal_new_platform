#!/usr/bin/env bash
#
# Суточный бэкап ФАЙЛОВ проекта: код и конфиги окружения (`.env` всех
# приложений) одним zip-архивом.
#
# Загрузки сайта (`data/uploads`) идут СВОИМ архивом — `backup-uploads.sh`:
# это пользовательские файлы, они меняются своим ритмом и нужны отдельно
# (картинки и документы страниц), тогда как здесь — то, что приезжает из
# git плюс окружение. Базы — третий архив (`backup-databases.sh`).
#
# Что НЕ попадает в архив (появится само при деплое или лежит в другом
# бэкапе):
#   - `.git` — история есть на GitHub;
#   - `vendor/`, `node_modules/` — composer install / npm install;
#   - `.output/`, `.nuxt/`, `dist/`, `public/build/` — сборки фронтов;
#   - `bootstrap/cache/`, `storage/framework/*` — кэши Laravel;
#   - `storage/logs/` — журналы, для восстановления не нужны;
#   - `data/postgres/` — файлы БД (их бэкапит backup-databases.sh);
#   - `data/uploads/` — загрузки сайта (их бэкапит backup-uploads.sh);
#   - `databases/` — копии legacy Firebird (исходники переноса, 7+ ГБ).
#
# Параметры (переменные окружения): PROJECT_DIR, BACKUP_DIR, KEEP.
set -euo pipefail

PROJECT_DIR=${PROJECT_DIR:-/data/avtovokzal_new_platform}
BACKUP_DIR=${BACKUP_DIR:-/mnt/raid1/backups}
KEEP=${KEEP:-10}

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die() { log "ОШИБКА: $*"; exit 1; }

exec 9>/var/lock/avtovokzal-files-backup.lock
flock -n 9 || { log "Бэкап файлов уже выполняется — выходим"; exit 0; }

[ -d "$PROJECT_DIR" ] || die "нет каталога проекта $PROJECT_DIR"
[ -d "$BACKUP_DIR" ] || die "нет каталога для бэкапов $BACKUP_DIR"

cd "$PROJECT_DIR"

stamp=$(date '+%Y-%m-%d_%H%M')
archive="$BACKUP_DIR/avtovokzal-files-${stamp}.zip"

# Исключения для zip: пути относительно каталога проекта.
EXCLUDES=(
    '.git/*'
    '*/node_modules/*'
    '*/vendor/*'
    'frontend/.output/*'
    'frontend/.nuxt/*'
    '*/dist/*'
    '*/public/build/*'
    '*/public/hot'
    '*/bootstrap/cache/*'
    '*/storage/framework/cache/*'
    '*/storage/framework/sessions/*'
    '*/storage/framework/views/*'
    '*/storage/logs/*'
    'data/postgres/*'
    'data/uploads/*'
    'databases/*'
    # Распакованный бэкап боевого legacy — он не меняется и есть у заказчика.
    'htdocs/*'
    # Сами бэкапы, если каталог окажется внутри проекта.
    '*.zip'
)

log "Бэкап файлов начат: $PROJECT_DIR → $archive"

zip_args=()
for pattern in "${EXCLUDES[@]}"; do
    zip_args+=(-x "$pattern")
done

# -y: симлинки сохраняем ссылками, а не тянем их цели.
zip -q -9 -r -y "$archive" . "${zip_args[@]}"

zip -T "$archive" > /dev/null || die "архив не прошёл проверку целостности"

# В архиве лежат `.env` с паролями шлюза, почты и баз — читать его должен
# только root (в каталоге рядом по той же причине лежат дампы БД).
chmod 600 "$archive"

# Проверяем, что главное на месте: без `.env` восстановление бессмысленно.
# Имена в архиве — без ведущего «./», zip его срезает, поэтому сверяем по
# точному совпадению со списком содержимого.
names=$(unzip -Z1 "$archive")

for must in '.env' 'backend/.env' 'docker-compose.yml'; do
    grep -qxF "$must" <<< "$names" \
        || die "в архиве нет $must — состав бэкапа не тот, что ожидался"
done

log "Готово: $archive ($(du -h "$archive" | cut -f1), файлов: $(wc -l <<< "$names"))"

# Ротация: оставляем KEEP последних архивов файлов (архивы баз не трогаем —
# у них своё имя и своя ротация).
mapfile -t stale < <(ls -1t "$BACKUP_DIR"/avtovokzal-files-*.zip 2>/dev/null | tail -n +$((KEEP + 1)))

for f in "${stale[@]:-}"; do
    [ -n "$f" ] || continue
    log "Удаляю старую копию: $(basename "$f")"
    rm -f -- "$f"
done

log "Копий файлов в каталоге: $(ls -1 "$BACKUP_DIR"/avtovokzal-files-*.zip 2>/dev/null | wc -l) (храним $KEEP)"
