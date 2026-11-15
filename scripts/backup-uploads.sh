#!/usr/bin/env bash
#
# Суточный бэкап ЗАГРУЗОК сайта (`data/uploads`) отдельным zip-архивом.
#
# Это единственные файлы, которых нет больше нигде: картинки и документы
# текстовых страниц, новостей и блоков сайта грузят через админку АИС, в git
# они не попадают, а в дампе БД лежат только ссылки на них. Потеряв этот
# каталог, восстановить картинки будет нечем.
#
# Отдельно от кода (`backup-files.sh`) и баз (`backup-databases.sh`): у них
# разный ритм изменений, и разбирать один большой архив ради одной картинки
# неудобно.
#
# Параметры (переменные окружения): PROJECT_DIR, BACKUP_DIR, KEEP, UPLOADS_DIR.
set -euo pipefail

PROJECT_DIR=${PROJECT_DIR:-/data/avtovokzal_new_platform}
BACKUP_DIR=${BACKUP_DIR:-/mnt/raid1/backups}
KEEP=${KEEP:-10}
UPLOADS_DIR=${UPLOADS_DIR:-data/uploads}

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die() { log "ОШИБКА: $*"; exit 1; }

exec 9>/var/lock/avtovokzal-uploads-backup.lock
flock -n 9 || { log "Бэкап загрузок уже выполняется — выходим"; exit 0; }

[ -d "$PROJECT_DIR" ] || die "нет каталога проекта $PROJECT_DIR"
[ -d "$BACKUP_DIR" ] || die "нет каталога для бэкапов $BACKUP_DIR"

cd "$PROJECT_DIR"

[ -d "$UPLOADS_DIR" ] || die "нет каталога загрузок $UPLOADS_DIR"

stamp=$(date '+%Y-%m-%d_%H%M')
archive="$BACKUP_DIR/avtovokzal-uploads-${stamp}.zip"

log "Бэкап загрузок начат: $PROJECT_DIR/$UPLOADS_DIR → $archive"

# Каталог кладём вместе с его путём (`data/uploads/...`), чтобы архив
# распаковывался прямо в корень проекта.
zip -q -9 -r -y "$archive" "$UPLOADS_DIR"

zip -T "$archive" > /dev/null || die "архив не прошёл проверку целостности"

files=$(unzip -l "$archive" | tail -1 | awk '{print $2}')

# Пустой каталог загрузок бывает только на свежем сервере: на боевом это
# признак, что примонтировано не то — такой «бэкап» прикрывает потерю.
[ "${files:-0}" -gt 0 ] || die "в каталоге загрузок нет файлов"

log "Готово: $archive ($(du -h "$archive" | cut -f1), файлов: $files)"

# Ротация: свои KEEP последних копий, архивы баз и кода не трогаем.
mapfile -t stale < <(ls -1t "$BACKUP_DIR"/avtovokzal-uploads-*.zip 2>/dev/null | tail -n +$((KEEP + 1)))

for f in "${stale[@]:-}"; do
    [ -n "$f" ] || continue
    log "Удаляю старую копию: $(basename "$f")"
    rm -f -- "$f"
done

log "Копий загрузок в каталоге: $(ls -1 "$BACKUP_DIR"/avtovokzal-uploads-*.zip 2>/dev/null | wc -l) (храним $KEEP)"
