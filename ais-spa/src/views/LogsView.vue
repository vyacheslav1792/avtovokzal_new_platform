<script setup>
/**
 * Журналы приложений: что сайт, АИС, продажи и фискализация пишут в
 * storage/logs.
 *
 * Раньше их смотрели единственным способом — ssh на сервер и
 * `docker exec … tail storage/logs/laravel.log`, то есть ответить
 * «почему страница отдала Server Error» мог только тот, у кого есть
 * доступ к серверу.
 *
 * Файлы читаются с конца порциями (на проде journal сайта под 70 МБ),
 * поэтому «показать ещё» ходит за следующей порцией с позицией в файле,
 * а не номером страницы.
 */
import { computed, onMounted, ref } from 'vue'
import { fetchLogEntries, fetchLogSources, logDownloadUrl } from '../api'
import { can } from '../auth'

// Уровни Monolog от тяжёлого к мелкому. Выбирать можно несколько:
// разбираются обычно «ошибки и предупреждения вместе», а не один уровень.
const LEVELS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG']

const sources = ref([])
const source = ref('')
const file = ref('')
// Пустой набор = любой уровень.
const levels = ref([])
const q = ref('')

const entries = ref([])
const info = ref(null)
const nextBefore = ref(null)
const truncated = ref(false)
const loading = ref(false)
const loadingMore = ref(false)
const error = ref('')

// Раскрытые стектрейсы: по умолчанию свёрнуты — иначе одна ошибка Laravel
// занимает весь экран.
const opened = ref(new Set())

const canDownload = computed(() => can('logs.download'))
const current = computed(() => sources.value.find((s) => s.key === source.value) || null)
const files = computed(() => current.value?.files || [])

async function loadSources() {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchLogSources()
    sources.value = data.sources
    // Открываем первый доступный журнал, у которого есть файлы.
    const first = sources.value.find((s) => s.available && s.files.length) || sources.value[0]
    if (first) {
      source.value = first.key
      await load()
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function load() {
  loading.value = true
  error.value = ''
  opened.value = new Set()
  try {
    const data = await fetchLogEntries(source.value, {
      file: file.value, levels: levels.value.join(','), q: q.value, limit: 50,
    })
    entries.value = data.entries
    info.value = data
    nextBefore.value = data.next_before
    truncated.value = data.truncated
  } catch (e) {
    entries.value = []
    info.value = null
    nextBefore.value = null
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function more() {
  if (nextBefore.value === null) return

  loadingMore.value = true
  error.value = ''
  try {
    const data = await fetchLogEntries(source.value, {
      file: file.value, levels: levels.value.join(','), q: q.value, limit: 50, before: nextBefore.value,
    })
    entries.value = [...entries.value, ...data.entries]
    nextBefore.value = data.next_before
    truncated.value = data.truncated
  } catch (e) {
    error.value = e.message
  } finally {
    loadingMore.value = false
  }
}

/** Переключить уровень в наборе. Снять все = показывать любые. */
function toggleLevel(value) {
  levels.value = levels.value.includes(value)
    ? levels.value.filter((l) => l !== value)
    : [...levels.value, value]
  load()
}

function allLevels() {
  if (!levels.value.length) return
  levels.value = []
  load()
}

/** Быстрый набор: то, из-за чего в журнал и заглядывают. */
function onlyProblems() {
  levels.value = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING']
  load()
}

function pick(key) {
  source.value = key
  file.value = ''
  load()
}

function toggle(index) {
  const next = new Set(opened.value)
  if (next.has(index)) next.delete(index)
  else next.add(index)
  opened.value = next
}

function size(bytes) {
  if (!bytes) return '0 КБ'
  return bytes > 1048576
    ? `${(bytes / 1048576).toFixed(1)} МБ`
    : `${Math.max(1, Math.round(bytes / 1024))} КБ`
}

/** Класс уровня: ошибки и предупреждения видно без чтения текста. */
function levelClass(value) {
  if (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'].includes(value)) return 'lg-error'
  if (value === 'WARNING') return 'lg-warn'
  if (['NOTICE', 'INFO'].includes(value)) return 'lg-info'
  return 'lg-debug'
}

onMounted(() => {
  document.title = 'Журналы · АИС'
  loadSources()
})
</script>

<template>
  <div>
    <h1>Журналы приложений</h1>
    <p class="muted">
      Записи из <code>storage/logs</code>. Файлы читаются с конца, поэтому сначала
      видно самое свежее; «Показать ещё» дочитывает журнал в прошлое.
    </p>

    <div class="fb-tabs">
      <button
        v-for="s in sources"
        :key="s.key"
        class="fb-tab"
        :class="{ active: source === s.key }"
        :disabled="!s.available"
        :title="s.hint || ''"
        @click="pick(s.key)"
      >
        {{ s.title }}
        <span v-if="!s.available" class="fb-count">нет доступа</span>
        <span v-else-if="!s.files.length" class="fb-count">пусто</span>
      </button>
    </div>

    <div class="fb-filters">
      <label v-if="files.length > 1" class="fb-field">
        <span class="fb-label">Файл</span>
        <select v-model="file" @change="load">
          <option value="">свежий</option>
          <option v-for="f in files" :key="f.name" :value="f.name">
            {{ f.name }} — {{ size(f.size) }}
          </option>
        </select>
      </label>
      <label class="fb-field fb-grow">
        <span class="fb-label">Поиск по тексту записи и стектрейсу</span>
        <input v-model="q" type="search" placeholder="например, SQLSTATE или номер заказа" @keyup.enter="load" />
      </label>
      <button class="btn primary" :disabled="loading" @click="load">Показать</button>
      <a
        v-if="canDownload && current?.files.length"
        class="btn"
        :href="logDownloadUrl(source, file)"
        target="_blank"
        rel="noopener"
      >Скачать файл</a>
    </div>

    <div class="lg-levels">
      <span class="fb-label">Уровни:</span>
      <button class="lg-chip" :class="{ active: !levels.length }" @click="allLevels">любые</button>
      <button
        v-for="l in LEVELS"
        :key="l"
        class="lg-chip"
        :class="[levelClass(l), { active: levels.includes(l) }]"
        @click="toggleLevel(l)"
      >{{ l }}</button>
      <button class="lg-chip lg-chip-wide" @click="onlyProblems">только проблемы</button>
    </div>

    <div v-if="error" class="alert">{{ error }}</div>

    <p v-if="info" class="muted lg-info-line">
      {{ info.file }} · {{ size(info.size) }} · изменён {{ info.modified_at }} ·
      просмотрено {{ size(info.scanned_bytes) }}
    </p>

    <div v-if="truncated" class="alert warn">
      Журнал просмотрен не до начала — показано то, что нашлось в последних
      просмотренных мегабайтах. Уточните поиск или уровень.
    </div>

    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <p v-if="!entries.length" class="muted">Записей нет.</p>

      <div v-else class="lg-list">
        <div v-for="(e, i) in entries" :key="i" class="lg-entry" :class="levelClass(e.level)">
          <div class="lg-head" :class="{ clickable: e.details }" @click="e.details && toggle(i)">
            <span class="lg-time">{{ e.time }}</span>
            <span class="lg-level">{{ e.level }}</span>
            <span class="lg-msg">{{ e.message }}</span>
            <span v-if="e.details" class="lg-toggle">{{ opened.has(i) ? '−' : '+' }}</span>
          </div>
          <pre v-if="e.details && opened.has(i)" class="lg-details">{{ e.details }}<template
            v-if="e.details_truncated"
          >
… дальше обрезано, полный текст — в файле журнала</template></pre>
        </div>
      </div>

      <p v-if="nextBefore !== null" class="lg-more">
        <button class="btn" :disabled="loadingMore" @click="more">
          {{ loadingMore ? 'Читаем…' : 'Показать ещё' }}
        </button>
      </p>
    </template>
  </div>
</template>

<style scoped>
/* Вкладки, фильтры и поля — общие классы списка (style.css). */
.fb-field select {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; width: 100%;
}
.fb-tab:disabled { opacity: .5; cursor: default; }

.lg-levels { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 12px; }
.lg-chip {
  padding: 3px 9px; border: 1px solid var(--line-strong); border-radius: 999px;
  background: #fff; cursor: pointer; font: inherit; font-size: 12px; font-weight: 600;
}
.lg-chip:hover { border-color: var(--accent); }
/* Выбранный уровень заливается — видно, что фильтр включён, даже если
   выбрано сразу несколько. */
.lg-chip.active { background: var(--accent); color: #fff; border-color: transparent; }
.lg-chip-wide { font-weight: 400; margin-left: 6px; }

.lg-info-line { font-size: 13px; margin: 4px 0 10px; }
.lg-list { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; background: #fff; }
.lg-entry { border-bottom: 1px solid var(--line); }
.lg-entry:last-child { border-bottom: none; }

.lg-head {
  display: flex; gap: 10px; align-items: baseline;
  padding: 7px 10px; font-size: 13px;
}
.lg-head.clickable { cursor: pointer; }
.lg-head.clickable:hover { background: #f3f6ff; }

.lg-time { color: var(--muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
.lg-level { font-weight: 600; font-size: 12px; white-space: nowrap; }
/* Сообщение может быть в несколько строк — не обрезаем: по хвосту текста
   как раз и понятно, что случилось. */
.lg-msg { flex: 1; word-break: break-word; font-family: ui-monospace, Menlo, Consolas, monospace; }
.lg-toggle { color: var(--muted); font-weight: 600; }

/* Цвет — по тяжести, как подсветка строк в отчётах. */
.lg-error { background: #ffecec; }
.lg-error .lg-level { color: #b00; }
.lg-warn { background: #fff7cf; }
.lg-warn .lg-level { color: #8a6d00; }
.lg-info .lg-level { color: #2a5db0; }
.lg-debug .lg-level { color: var(--muted); }

.lg-details {
  margin: 0; padding: 10px 12px; background: #fafafa; border-top: 1px solid var(--line);
  font-size: 12px; white-space: pre-wrap; word-break: break-word; max-height: 420px; overflow: auto;
}
.lg-more { margin: 12px 0 40px; }
</style>
