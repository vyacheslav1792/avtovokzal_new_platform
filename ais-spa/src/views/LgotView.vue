<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  addLgotExcludedTickets, clearLgotExcludedTickets, fetchLgotAtps, fetchLgotExclusions,
  fetchMeta, removeLgotExcludedTickets, runReport, saveLgotAtpList,
} from '../api'
import { can } from '../auth'
import MultiSelect from '../components/MultiSelect.vue'

/*
 * Отчёт «Нулевые билеты» — перенос ТРЁХ страниц legacy с почти
 * одинаковым кодом: ais/lgot_prig.php (пригородные), ais/lgot2.php
 * (внутриобластные) и ais/lgot.php (межобластные). Отличались они видом
 * рейса и списками исключений, поэтому здесь это один отчёт с
 * переключателем «Рейсы»; вид живёт в адресе (`?kind=`), чтобы ссылкой
 * можно было поделиться и чтобы он пережил перезагрузку страницы.
 *
 * Страница своя, а не универсальный рендер отчётов: кроме таблицы тут
 * отметка билетов к исключению, два списка исключений и выгрузка в HTML.
 *
 * Главное отличие от legacy: СПИСОК АТП ЗАПРАШИВАЕТСЯ САМ. В legacy он
 * наполнялся из результата расчёта, и первой строкой подсказки стояло
 * «при смене диапазона дат или автостанции сначала расчёт делать по всем
 * АТП для обновления списка АТП» — иначе в селекте оставались АТП от
 * прошлого периода. Теперь при смене периода, хозяйства, вида рейса или
 * типа билета список приходит отдельным запросом (билеты при этом не
 * выбираются).
 */

const SLUG = 'lgot'

const meta = ref(null)
const metaErr = ref('')

const route = useRoute()
const router = useRouter()

const form = reactive({
  // Вид рейса: пригородные | внутриобластные | межобластные.
  kind: '',
  date1: '',
  date2: '',
  // Хозяйство и АТП — списки: выбрать можно несколько, пустой = все.
  stan: [],
  lgot: '-1',
  atp: [],
  iskl: false,
  iskl_tick: true,
  list_atp: '',
})

// Скрытие ФИО — только показ, на расчёт не влияет (в legacy тоже клиент).
const hideFio = ref(false)

const loading = ref(false)
const runErr = ref('')
const report = ref(null)
const notice = ref('')

const atps = ref({})
const atpsLoading = ref(false)
const atpsErr = ref('')

const listTickets = ref([])
const savedListAtp = ref('')
const savingAtp = ref(false)

const selected = reactive({})
const pendingDel = reactive({})
const showOnlySel = ref(false)

const sortKey = ref('')
const sortDir = ref(0) // 0 — исходный порядок, 1 — по возрастанию, -1 — по убыванию

const canExclude = computed(() => can(`report:${SLUG}.exclude`))

const kindOptions = computed(() => filterOptions('kind'))

/** Опции АТП: их отдаёт отдельный запрос, а не meta отчёта. */
const atpOptions = computed(
  () => Object.entries(atps.value).map(([value, label]) => ({ value, label })),
)
const kindTitle = computed(
  () => kindOptions.value.find((o) => o.value === form.kind)?.label || '',
)

/* ── Загрузка ─────────────────────────────────────────────────────────── */

onMounted(async () => {
  try {
    const d = await fetchMeta(SLUG)
    meta.value = d.meta
    document.title = (d.meta?.title || 'Отчёт') + ' · АИС'
    // Значения по умолчанию (прошлый месяц, первое хозяйство) задаёт
    // сервер — там же, где живёт сам отчёт.
    for (const f of d.meta?.filters || []) {
      if (f.name in form && f.default !== undefined) form[f.name] = f.default
    }
  } catch (e) {
    metaErr.value = e.message
    return
  }

  // Вид рейса из адреса важнее умолчания отчёта: по ссылке приходят
  // именно за ним.
  const fromUrl = String(route.query.kind || '')
  if (fromUrl && kindOptions.value.some((o) => o.value === fromUrl)) form.kind = fromUrl
  syncKindToUrl()

  await loadExclusions()
  loadAtps()
})

/** Списки исключений — свои у каждого вида рейса. */
async function loadExclusions() {
  try {
    const d = await fetchLgotExclusions(form.kind)
    savedListAtp.value = d.list_atp || ''
    form.list_atp = savedListAtp.value
    listTickets.value = d.list_tickets || []
  } catch (e) {
    metaErr.value = e.message
  }
}

/** Вид рейса держим в адресе: ссылкой на отчёт делятся. */
function syncKindToUrl() {
  if (String(route.query.kind || '') === form.kind) return
  router.replace({ query: { ...route.query, kind: form.kind } })
}

/*
 * Смена вида рейса — это другой отчёт: другие списки исключений, другой
 * набор АТП и другие строки. Прежнюю выдачу убираем, иначе на экране
 * остались бы, например, межобластные билеты под заголовком
 * «Пригородные».
 */
watch(() => form.kind, async (kind, was) => {
  if (!kind || !was) return
  syncKindToUrl()
  report.value = null
  runErr.value = ''
  notice.value = ''
  form.atp = []
  clearMarks()
  await loadExclusions()
  loadAtps()
})

const filterOptions = (name) => meta.value?.filters?.find((f) => f.name === name)?.options || []

/**
 * Список АТП за период. Запрашивается при смене периода, хозяйства, вида
 * рейса, типа билета и галочки «Исключить атп по договорам» — набор АТП
 * зависит от всех шести. Текст списка договоров уходит с запросом (ещё не
 * сохранённый), иначе селект показывал бы АТП, которые в расчёт не идут.
 */
let atpTimer = null

function scheduleAtps() {
  clearTimeout(atpTimer)
  atpTimer = setTimeout(loadAtps, 400)
}

async function loadAtps() {
  if (!form.date1) return
  atpsLoading.value = true
  atpsErr.value = ''
  try {
    const d = await fetchLgotAtps({
      kind: form.kind,
      date1: form.date1,
      date2: form.date2 || form.date1,
      stan: form.stan,
      lgot: form.lgot,
      iskl: form.iskl ? 1 : 0,
      list_atp: form.iskl ? form.list_atp : '',
    })
    atps.value = d.atps || {}
    atpsErr.value = d.errors ? d.errors.replace(/<br\s*\/?>/gi, ' ').trim() : ''
    // Выбранные АТП могли исчезнуть вместе со сменой периода: отбор по
    // коду, которого в списке нет, молча дал бы пустой отчёт.
    const known = Object.keys(atps.value)
    form.atp = form.atp.filter((code) => known.includes(String(code)))
  } catch (e) {
    atpsErr.value = e.message
  } finally {
    atpsLoading.value = false
  }
}

// Массивы в watch сравниваются по ссылке, поэтому следим за их составом.
watch(
  () => [form.date1, form.date2, form.stan.join(','), form.lgot, form.iskl].join('|'),
  scheduleAtps,
)

/* ── Расчёт ───────────────────────────────────────────────────────────── */

async function calc() {
  if (loading.value) return
  loading.value = true
  runErr.value = ''
  notice.value = ''
  try {
    // Пометки «убрать из исключаемых» применяются при расчёте — как в
    // legacy (там это был единственный способ их применить).
    const del = Object.keys(pendingDel).filter((k) => pendingDel[k])
    if (del.length && canExclude.value) {
      const d = await removeLgotExcludedTickets(form.kind, del)
      listTickets.value = d.list_tickets || []
    }

    const d = await runReport(SLUG, { ...form })
    report.value = d
    listTickets.value = d.list_tickets || []
    savedListAtp.value = d.list_atp ?? savedListAtp.value
    clearMarks()
  } catch (e) {
    runErr.value = e.message
    report.value = null
  } finally {
    loading.value = false
  }
}

function clearMarks() {
  for (const k in selected) delete selected[k]
  for (const k in pendingDel) delete pendingDel[k]
  showOnlySel.value = false
  sortKey.value = ''
  sortDir.value = 0
}

/* ── Колонки и строки ─────────────────────────────────────────────────── */

const columns = computed(() => report.value?.sections?.[0]?.columns || [])
const rows = computed(() => report.value?.sections?.[0]?.rows || [])
const totals = computed(() => report.value?.sections?.[0]?.totals || {})

const visibleColumns = computed(
  () => columns.value.filter((c) => !(hideFio.value && c.fio)),
)

// В списке исключённых своя нумерация, поэтому колонка «№» там не нужна.
const listColumns = computed(() => visibleColumns.value.filter((c) => c.key !== 'i'))

const sortedRows = computed(() => {
  let out = rows.value
  if (showOnlySel.value) out = out.filter((r) => selected[r.tick_key])
  if (!sortKey.value || sortDir.value === 0) return out
  const key = sortKey.value
  const dir = sortDir.value

  return out.slice().sort((a, b) => dir * String(a[key] ?? '')
    .localeCompare(String(b[key] ?? ''), 'ru', { numeric: true }))
})

const sortHint = computed(() => {
  if (!sortKey.value || sortDir.value === 0) return 'сейчас: исходный порядок'

  return sortDir.value === 1 ? 'сейчас: по возрастанию' : 'сейчас: по убыванию'
})

function toggleSort(key) {
  // по возрастанию → по убыванию → исходный порядок
  if (sortKey.value !== key) {
    sortKey.value = key
    sortDir.value = 1
  } else if (sortDir.value === 1) {
    sortDir.value = -1
  } else {
    sortKey.value = ''
    sortDir.value = 0
  }
}

const selectedCount = computed(() => Object.values(selected).filter(Boolean).length)
const pendingDelCount = computed(() => Object.values(pendingDel).filter(Boolean).length)

function toggleSel(key, checked) {
  if (checked) selected[key] = true
  else delete selected[key]
}

/** Деньги — как в остальных отчётах АИС: 1 234,56. */
function money(v) {
  if (v === '' || v === null || v === undefined) return ''
  const n = Number(v)

  return isFinite(n)
    ? n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : v
}

function cell(row, col) {
  return col.type === 'money' ? money(row[col.key]) : row[col.key]
}

function totalCell(col) {
  const v = totals.value[col.key]
  if (v === undefined) return ''

  return col.type === 'money' ? money(v) : v
}

function detClass(value) {
  return value === 'Детский' ? 'det-child' : 'det-adult'
}

function fmtDate(iso) {
  if (!iso) return ''
  const p = String(iso).split('-')

  return `${p[2]}.${p[1]}.${p[0]}`
}

/* ── Отметки и списки исключений ──────────────────────────────────────── */

/** «Социальный» с удостоверением не из 4 цифр — главное, что ищут глазами. */
function markBadUdost() {
  const re = /^\d{4}$/
  let found = 0
  for (const r of rows.value) {
    if (r.lgot === 'Социальный' && !re.test(String(r.udost ?? '').trim())) {
      selected[r.tick_key] = true
      found++
    }
  }
  notice.value = found
    ? `Отмечено билетов: ${found}`
    : 'Билетов «Социальный» с удостоверением не из 4 цифр не найдено'
}

async function excludeSelected() {
  const picked = rows.value.filter((r) => selected[r.tick_key])
  if (!picked.length) return
  loading.value = true
  runErr.value = ''
  notice.value = ''
  try {
    const d = await addLgotExcludedTickets(form.kind, picked)
    listTickets.value = d.list_tickets || []
    loading.value = false
    // Пересчёт: исключённые билеты должны уйти из выдачи сразу. Сообщение
    // ставим ПОСЛЕ него — расчёт чистит прежние сообщения.
    await calc()
    notice.value = `Добавлено в исключаемые: ${d.added}`
  } catch (e) {
    runErr.value = e.message
    loading.value = false
  }
}

/** Пометка к удалению; на сервер уйдёт кнопкой «Применить удаление» или расчётом. */
function removeTick(key) {
  if (pendingDel[key]) delete pendingDel[key]
  else pendingDel[key] = true
}

async function applyPendingDel() {
  const del = Object.keys(pendingDel).filter((k) => pendingDel[k])
  if (!del.length) return
  loading.value = true
  runErr.value = ''
  notice.value = ''
  let message = ''
  try {
    const d = await removeLgotExcludedTickets(form.kind, del)
    listTickets.value = d.list_tickets || []
    for (const k in pendingDel) delete pendingDel[k]
    message = `Убрано из исключаемых: ${d.removed}`
  } catch (e) {
    runErr.value = e.message
  } finally {
    loading.value = false
  }
  if (report.value) await calc()
  if (message) notice.value = message
}

async function clearTickets() {
  if (!confirm('Очистить весь список исключённых билетов?')) return
  loading.value = true
  runErr.value = ''
  notice.value = ''
  let message = ''
  try {
    const d = await clearLgotExcludedTickets(form.kind)
    listTickets.value = d.list_tickets || []
    message = `Список исключённых билетов очищен (убрано ${d.removed})`
  } catch (e) {
    runErr.value = e.message
  } finally {
    loading.value = false
  }
  if (report.value) await calc()
  if (message) notice.value = message
}

/*
 * Сохранение списка АТП — ОТДЕЛЬНОЕ действие. В legacy список уходил в
 * файл при каждом расчёте, то есть правка сохранялась незаметно для
 * сотрудника, а посчитать «на пробу» с другим списком было нельзя. Здесь
 * расчёт идёт по тому, что набрано в поле, а кнопка сохраняет список для
 * следующих открытий отчёта (и требует своего права).
 */
const atpListChanged = computed(() => form.list_atp !== savedListAtp.value)

async function saveAtpList() {
  savingAtp.value = true
  runErr.value = ''
  notice.value = ''
  try {
    const d = await saveLgotAtpList(form.kind, form.list_atp)
    savedListAtp.value = d.list_atp || ''
    form.list_atp = savedListAtp.value
    notice.value = `Список АТП по договорам сохранён (строк: ${d.saved})`
    loadAtps()
  } catch (e) {
    runErr.value = e.message
  } finally {
    savingAtp.value = false
  }
}

/* ── Выгрузка в HTML (перенос legacy downloadHtml) ────────────────────── */

function sanitizeFileName(s) {
  s = String(s ?? '')
    .replace(/[\x00-\x1f\\/:*?"<>|]/g, '_')
    .replace(/\s+/g, ' ')
    .replace(/^[\s.]+|[\s.]+$/g, '')
  if (s.length > 100) s = s.substring(0, 100)

  return s || 'otchet'
}

function downloadHtml() {
  if (!report.value) return
  const esc = (v) => String(v ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

  const title = (meta.value?.title || 'Нулевые билеты')
    + (report.value.kind_title ? ` · ${report.value.kind_title} рейсы` : '')
  const period = `C ${fmtDate(report.value.date1)} г. по ${fmtDate(report.value.date2)} г.`
  const cols = visibleColumns.value

  let html = '<!DOCTYPE html>\n<html lang="ru">\n<head>\n<meta charset="utf-8">\n<title>'
    + esc(title) + '</title>\n<style>\n'
    + 'body{font-family:Arial,sans-serif;font-size:14px;margin:10px;color:#222;}\n'
    + 'h2{font-size:17px;margin:0 0 4px;}\n'
    + '.muted{color:#555;font-size:13px;margin:0 0 10px;}\n'
    + '.sql_out{border:2px solid black;border-collapse:collapse;padding:2px;}\n'
    + '.sql_out td,.sql_out th{border:1px solid black;max-width:150px;padding:2px;}\n'
    + '.sql_out tbody tr:nth-child(odd){background-color:#faf6f0;}\n'
    + '.sql_out tbody tr:nth-child(even){background-color:#eaeff7;}\n'
    + '.sql_out tr.total-row td{font-weight:bold;background-color:#fff;}\n'
    + '.det-adult{background-color:green;color:#fff;text-align:center;}\n'
    + '.det-child{background-color:orange;text-align:center;}\n'
    + '</style>\n</head>\n<body>\n'
    + '<h2>' + esc(title) + '</h2>\n'
    + '<h2>' + esc(period) + '<br>Станция: ' + esc(report.value.stan_name) + '</h2>\n'
    + '<div class="muted">АТП: ' + esc(report.value.atp_name)
    + ' | Билетов: ' + esc(report.value.totals?.count) + '</div>\n'
    + '<table class="sql_out">\n<thead>\n<tr>'

  for (const c of cols) html += '<th>' + esc(c.title) + '</th>'
  html += '</tr>\n</thead>\n<tbody>\n'

  for (const row of sortedRows.value) {
    html += '<tr>'
    for (const c of cols) {
      const cls = c.key === 'det' ? ` class="${detClass(row.det)}"` : ''
      html += '<td' + cls + '>' + esc(cell(row, c)) + '</td>'
    }
    html += '</tr>\n'
  }

  if (!showOnlySel.value) {
    html += '<tr class="total-row">'
    for (const c of cols) html += '<td>' + esc(totalCell(c)) + '</td>'
    html += '</tr>\n'
  }
  html += '</tbody>\n</table>\n</body>\n</html>\n'

  // Вид рейса в имени файла: отчёты по трём видам складывают рядом.
  const name = sanitizeFileName(report.value.kind_title)
    + '--' + sanitizeFileName(report.value.stan_name)
    + '--' + sanitizeFileName(report.value.atp_name)
    + '--' + sanitizeFileName(fmtDate(report.value.date1) + '-' + fmtDate(report.value.date2))
    + '.html'

  const blob = new Blob([html], { type: 'text/html;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = name
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}
</script>

<template>
  <div class="lgot">
    <div class="noprint">
      <h1>
        {{ meta?.title || 'Нулевые билеты' }}<template v-if="kindTitle"> · {{ kindTitle }}</template>
      </h1>
      <div v-if="metaErr" class="alert">{{ metaErr }}</div>

      <div class="alert warn lgot-hint">
        <p v-if="form.kind === 'prig'">
          * Учитываются билеты фактически уехавшие за отчётный период
          (вычеркнутые, возвращённые и нераспечатанные не учитываются).
        </p>
        <p v-else>
          * Учитываются билеты за отчётный период, кроме вычеркнутых и
          возвращённых. Нераспечатанные по этим рейсам учитываются — так
          считал и прежний отчёт.
        </p>
        <p>* Списки исключений у каждого вида рейсов свои.</p>
        <p>
          * При проставленной галочке «Исключить атп по договорам» из расчёта
          исключаются АТП, перечисленные в списке.
        </p>
        <p>
          * При проставленной галочке «Применять исключение билетов» из расчёта
          исключаются билеты, перечисленные в «Исключено билетов».
        </p>
        <p>
          * Для пополнения списка «Исключено билетов» отметьте нужные билеты
          галочками и нажмите «Добавить отмеченные в исключаемые».
        </p>
        <p>
          * Список АТП обновляется сам при смене вида рейсов, периода, хозяйства
          и типа билета.
        </p>
      </div>

      <form v-if="meta" class="filters" @submit.prevent="calc">
        <div class="filters-grid">
          <label class="field">
            <span class="field-label">Рейсы</span>
            <select v-model="form.kind">
              <option v-for="o in kindOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">Дата начала</span>
            <input type="date" v-model="form.date1" required />
          </label>
          <label class="field">
            <span class="field-label">Дата окончания</span>
            <input type="date" v-model="form.date2" />
          </label>
          <div class="field lgot-pick">
            <span class="field-label">Хозяйство</span>
            <MultiSelect
              v-model="form.stan" :options="filterOptions('stan')"
              all-label="Все хозяйства" items-label="Хозяйств"
            />
          </div>
          <label class="field">
            <span class="field-label">Тип билета</span>
            <select v-model="form.lgot">
              <option v-for="o in filterOptions('lgot')" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <div class="field lgot-pick">
            <span class="field-label">
              АТП<span v-if="atpsLoading" class="muted"> — обновляется…</span>
            </span>
            <MultiSelect
              v-model="form.atp" :options="atpOptions" :loading="atpsLoading"
              all-label="Все атп" items-label="АТП"
            />
          </div>
          <label class="field field-check">
            <input type="checkbox" v-model="hideFio" />
            <span>Скрыть ФИО</span>
          </label>

          <div class="field lgot-atp-list">
            <label class="field-check">
              <input type="checkbox" v-model="form.iskl" />
              <span>Исключить атп по договорам</span>
            </label>
            <span class="field-label">Исключение по договорам (по одному АТП на строку)</span>
            <textarea rows="4" v-model="form.list_atp"></textarea>
            <div class="lgot-atp-actions">
              <button
                v-if="canExclude"
                type="button" class="btn small"
                :disabled="savingAtp || !atpListChanged"
                title="Сохранить список для следующих открытий отчёта"
                @click="saveAtpList"
              >Сохранить список</button>
              <span v-if="atpListChanged" class="muted lgot-small">не сохранён</span>
            </div>
          </div>
        </div>

        <div class="filters-actions">
          <button type="submit" class="btn primary" :disabled="loading">
            {{ loading ? 'Считаем…' : 'Посчитать' }}
          </button>
        </div>

        <div class="lgot-tick-block">
          <label class="field-check">
            <input type="checkbox" v-model="form.iskl_tick" />
            <span>Применять исключение билетов</span>
          </label>
          <button
            type="button" class="btn small"
            :disabled="loading || !listTickets.length || !canExclude"
            @click="clearTickets"
          >Очистить список исключений</button>
          <button
            v-if="pendingDelCount"
            type="button" class="btn small primary"
            :disabled="loading || !canExclude"
            @click="applyPendingDel"
          >Применить удаление ({{ pendingDelCount }})</button>

          <details class="lgot-tick-details">
            <summary>
              Исключено билетов: {{ listTickets.length }}
              <span v-if="pendingDelCount">
                (помечено к удалению: {{ pendingDelCount }})
              </span>
            </summary>
            <div v-if="!listTickets.length" class="muted">Список пуст</div>
            <div v-else class="dt-scroll lgot-tick-scroll">
              <table class="dt">
                <thead>
                  <tr>
                    <th title="Убрать билет из списка исключаемых"></th>
                    <th>№</th>
                    <th v-for="c in listColumns" :key="c.key">{{ c.title }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(t, idx) in listTickets" :key="t.tick_key"
                    :class="pendingDel[t.tick_key] ? 'lgot-tick-del' : null"
                  >
                    <td>
                      <button
                        type="button" class="btn small"
                        :disabled="loading || !canExclude"
                        :title="pendingDel[t.tick_key]
                          ? 'Отменить удаление'
                          : 'Пометить к удалению из списка исключаемых'"
                        @click="removeTick(t.tick_key)"
                      >{{ pendingDel[t.tick_key] ? '↩' : '✕' }}</button>
                    </td>
                    <td>{{ idx + 1 }}</td>
                    <td
                      v-for="c in listColumns" :key="c.key"
                      :class="c.key === 'det' && t.det ? detClass(t.det) : null"
                    >{{ cell(t, c) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </div>
      </form>

      <div v-if="atpsErr" class="alert warn">Список АТП: {{ atpsErr }}</div>
      <div v-if="runErr" class="alert">{{ runErr }}</div>
      <div v-if="notice" class="alert ok">{{ notice }}</div>
    </div>

    <div v-if="loading" class="muted loading">Формирование отчёта…</div>

    <template v-if="report">
      <div v-if="report.errors" class="alert warn" v-html="report.errors"></div>

      <div class="lgot-head">
        <h2>C {{ fmtDate(report.date1) }} г. по {{ fmtDate(report.date2) }} г.</h2>
        <div class="muted">
          Рейсы: {{ report.kind_title }} &nbsp;|&nbsp;
          Станция: {{ report.stan_name }} &nbsp;|&nbsp; АТП: {{ report.atp_name }}
          &nbsp;|&nbsp; Билетов: {{ report.totals.count }}
          &nbsp;|&nbsp; Стоимость билетов: {{ money(report.totals.money) }}
        </div>
      </div>

      <template v-if="rows.length">
        <p class="lgot-small muted noprint">
          Клик по заголовку «Удостоверение» — сортировка ({{ sortHint }})
        </p>

        <div class="lgot-actions noprint">
          <button
            type="button" class="btn primary"
            :disabled="loading || !selectedCount || !canExclude"
            @click="excludeSelected"
          >Добавить отмеченные в исключаемые ({{ selectedCount }})</button>
          <button
            type="button" class="btn"
            :disabled="loading"
            title="Отметить билеты типа «Социальный», у которых удостоверение не состоит ровно из 4 цифр"
            @click="markBadUdost"
          >Отметить «Социальный» с неверным удостоверением</button>
          <button
            type="button" class="btn"
            :disabled="loading || (!selectedCount && !showOnlySel)"
            @click="showOnlySel = !showOnlySel"
          >{{ showOnlySel ? 'Вернуться к обычному виду' : 'Показать только отмеченные' }}</button>
          <button
            type="button" class="btn"
            :disabled="loading"
            title="Сохранить текущий вид отчёта (как при печати) в HTML-файл"
            @click="downloadHtml"
          >Скачать HTML</button>
          <span v-if="showOnlySel" class="lgot-small muted">
            Показаны только отмеченные строки, «ИТОГО» скрыто
          </span>
        </div>

        <div class="dt-scroll">
          <table class="dt lgot-table">
            <thead>
              <tr>
                <th class="lgot-sel noprint" title="Отметьте строки для исключения из расчёта">
                  Искл.
                </th>
                <th
                  v-for="c in visibleColumns" :key="c.key"
                  :class="{ 'lgot-sortable': c.sortable, 'lgot-plain': !c.sortable }"
                  :title="c.sortable
                    ? 'Сортировка: клик — по возрастанию, ещё клик — по убыванию, ещё — исходный порядок'
                    : ''"
                  @click="c.sortable && toggleSort(c.key)"
                >
                  {{ c.title }}
                  <span v-if="c.sortable" class="th-sort">
                    {{ sortKey === c.key ? (sortDir === 1 ? '↑' : '↓') : '↕' }}
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in sortedRows" :key="row.i"
                :class="selected[row.tick_key] ? 'lgot-selected' : null"
              >
                <td class="lgot-sel noprint">
                  <input
                    type="checkbox" :checked="!!selected[row.tick_key]"
                    @change="toggleSel(row.tick_key, $event.target.checked)"
                  />
                  <span
                    v-if="row.in_iskl" class="lgot-mark"
                    title="Билет в списке исключений, но исключение сейчас выключено"
                  >искл.</span>
                </td>
                <td
                  v-for="c in visibleColumns" :key="c.key"
                  :class="c.key === 'det' ? detClass(row.det) : null"
                >{{ cell(row, c) }}</td>
              </tr>
              <tr v-if="!showOnlySel" class="lgot-total">
                <td class="lgot-sel noprint"></td>
                <td v-for="c in visibleColumns" :key="c.key">{{ totalCell(c) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
      <div v-else class="muted">За выбранный период данных нет.</div>
    </template>
  </div>
</template>

<style scoped>
.lgot-hint p { margin: 2px 0; font-size: 13px; }

/* Поля выбора шире обычных: в них помещаются названия хозяйств и АТП. */
.lgot-pick { min-width: 200px; max-width: 280px; }

.field-check { display: flex; flex-direction: row; align-items: center; gap: 6px; font-size: 13px; }
.field-check input { min-width: 0; }

/* Список договоров — своей строкой: в один ряд с датами он оттеснял
   галочку «Скрыть ФИО» под селекты. */
.lgot-atp-list { flex: 1 1 100%; max-width: 620px; }
.lgot-atp-list textarea {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font: inherit; font-size: 13px; background: #fff; resize: vertical;
}
.lgot-atp-actions { display: flex; align-items: center; gap: 8px; margin-top: 6px; }

.lgot-tick-block {
  display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center;
  border-top: 1px solid var(--line); margin-top: 14px; padding-top: 12px;
}
.lgot-tick-details { flex: 1 1 100%; font-size: 13px; }
.lgot-tick-details summary { cursor: pointer; color: var(--accent); }
.lgot-tick-scroll { max-height: 340px; margin-top: 8px; }
.lgot-tick-del td { text-decoration: line-through; color: var(--muted); }

.lgot-head { margin: 10px 0; }
.lgot-head h2 { font-size: 17px; margin: 0 0 4px; }
.lgot-small { font-size: 12px; }
.lgot-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }

/* Колонки сортируются только там, где это заявлено: сортировка в отчёте
   одна — по удостоверению (так было и в legacy). */
.lgot-table thead th.lgot-plain { cursor: default; }
.lgot-table thead th.lgot-plain:hover { background: var(--head); }
.lgot-sel { text-align: center; }
.lgot-mark { display: block; font-size: 11px; color: #8a1f1f; white-space: nowrap; }

/* Отмеченные к исключению строки должны бросаться в глаза. */
.lgot-table tbody tr.lgot-selected td { background: #ffd43b !important; font-weight: 700; }
.lgot-table tbody tr.lgot-selected td:first-child { border-left: 4px solid #e03131; }

.lgot-table tbody tr.lgot-total td { background: #fff !important; font-weight: 700; }

/*
 * На бумаге девятнадцать колонок обязаны влезть в лист: без явной ширины
 * таблица раскладывается по содержимому и «ДР … Стоимость билета» —
 * то, ради чего отчёт и печатают, — уезжает за правый край листа.
 */
@media print {
  .lgot-table { width: 100%; font-size: 9px; }
  /* break-word, а НЕ anywhere: anywhere разрешает рвать слова ради
     узкой колонки, и «Социальный» печатался тремя строками. */
  .lgot-table th, .lgot-table td { padding: 2px 3px; overflow-wrap: break-word; }
  .lgot-head { margin: 0 0 6px; }
}

/*
 * ТИП билета: взрослый/детский различают цветом — как в legacy. Селектор
 * специфичнее подсветки отмеченной строки СОЗНАТЕЛЬНО: цвет ТИПа — это
 * сведения о билете, и у отмеченной строки он пропадать не должен (в
 * legacy цвет тоже оставался: подсветка красила tr, а ТИП — td).
 */
.lgot-table tbody tr td.det-adult, .lgot-tick-scroll tbody tr td.det-adult {
  background: #1d7a33 !important; color: #fff; text-align: center;
}
.lgot-table tbody tr td.det-child, .lgot-tick-scroll tbody tr td.det-child {
  background: #f0a30a !important; text-align: center;
}
</style>
