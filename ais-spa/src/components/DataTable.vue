<script setup>
import { ref, reactive, computed, watch } from 'vue'

const props = defineProps({
  columns: { type: Array, required: true },   // [{key,title,type}]
  rows: { type: Array, required: true },
  totals: { type: Object, default: null },
})

// Внутренние ссылки (url с '/') не навигируют, а отдаются наверх —
// ReportView открывает их в оверлее, не сбрасывая сформированный отчёт.
const emit = defineEmits(['open-link'])

function isInternal(url) {
  return typeof url === 'string' && url.startsWith('/')
}

// --- Множественная сортировка: стек [{key, dir}], порядок = приоритет ---
const sorts = ref([])

const typeByKey = computed(() => {
  const m = {}
  for (const c of props.columns) m[c.key] = c.type
  return m
})

function sortState(key) {
  const i = sorts.value.findIndex((s) => s.key === key)
  return i === -1 ? null : { dir: sorts.value[i].dir, order: i + 1 }
}
function toggleSort(key) {
  const i = sorts.value.findIndex((s) => s.key === key)
  if (i === -1) sorts.value.push({ key, dir: 'asc' })
  else if (sorts.value[i].dir === 'asc') sorts.value[i].dir = 'desc'
  else sorts.value.splice(i, 1)
}
function resetSort() { sorts.value = [] }

// --- Клиентские фильтры по датам (по каждой колонке типа date: диапазон с/по) ---
const dateCols = computed(() => props.columns.filter((c) => c.type === 'date'))
const dateFilters = reactive({}) // key -> {from, to} в формате yyyy-mm-dd

watch(() => props.columns, () => {
  for (const c of props.columns) {
    if (c.type === 'date' && !(c.key in dateFilters)) dateFilters[c.key] = { from: '', to: '' }
  }
}, { immediate: true })

const anyFilter = computed(() => Object.values(dateFilters).some((f) => f && (f.from || f.to)))
function resetFilters() {
  for (const k in dateFilters) { dateFilters[k].from = ''; dateFilters[k].to = '' }
}

// --- Приведение к сравнимому виду ---
function toNum(x) {
  if (x === '' || x === null || x === undefined) return -Infinity
  const n = parseFloat(x)
  return isNaN(n) ? -Infinity : n
}
function toTs(x) {
  if (!x) return -Infinity
  const m = String(x).match(/^(\d{2})\.(\d{2})\.(\d{4})$/) // dd.mm.yyyy
  if (!m) return -Infinity
  return Date.UTC(+m[3], +m[2] - 1, +m[1])
}
function isoTs(x) {
  if (!x) return null
  const m = String(x).match(/^(\d{4})-(\d{2})-(\d{2})$/) // yyyy-mm-dd (input date)
  if (!m) return null
  return Date.UTC(+m[1], +m[2] - 1, +m[3])
}
// Значение ячейки для сравнения/вывода: у type=link это {text, url}.
function cellValue(v, type) {
  if (type === 'link') return v && typeof v === 'object' ? (v.text ?? '') : (v ?? '')
  return v
}
function cmp(a, b, type) {
  a = cellValue(a, type); b = cellValue(b, type)
  if (type === 'money' || type === 'number') return toNum(a) - toNum(b)
  if (type === 'date') return toTs(a) - toTs(b)
  return String(a ?? '').localeCompare(String(b ?? ''), 'ru')
}

// Строка проходит фильтр, если по каждой дате попадает в заданный диапазон.
function passFilters(row) {
  for (const c of dateCols.value) {
    const f = dateFilters[c.key]
    if (!f || (!f.from && !f.to)) continue
    const ts = toTs(row[c.key])
    if (ts === -Infinity) return false // фильтр активен, а даты нет — скрываем
    const from = isoTs(f.from), to = isoTs(f.to)
    if (from !== null && ts < from) return false
    if (to !== null && ts > to) return false
  }
  return true
}

const filteredRows = computed(() => {
  if (!anyFilter.value) return props.rows
  return props.rows.filter(passFilters)
})

const displayRows = computed(() => {
  if (!sorts.value.length) return filteredRows.value
  const arr = filteredRows.value.slice()
  const S = sorts.value, T = typeByKey.value
  arr.sort((ra, rb) => {
    for (const s of S) {
      const c = cmp(ra[s.key], rb[s.key], T[s.key])
      if (c !== 0) return s.dir === 'asc' ? c : -c
    }
    return 0
  })
  return arr
})

// Ссылка ячейки type=link (null — рисуем просто текст).
function cellLink(row, col) {
  if (col.type !== 'link') return null
  const v = row[col.key]
  return v && typeof v === 'object' && v.url ? v.url : null
}
function cellDisplay(row, col) {
  const v = cellValue(row[col.key], col.type)
  if (col.type === 'money') {
    if (v === '' || v === null || v === undefined) return ''
    const n = Number(v)
    return isFinite(n) ? n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v
  }
  return v
}
// Итоги: без фильтров — серверные (точные). При активных фильтрах — пересчёт
// денежных колонок по отфильтрованным строкам, текстовые метки (ИТОГО:) сохраняем.
const footerTotals = computed(() => {
  if (!props.totals) return null
  if (!anyFilter.value) return props.totals
  const out = {}
  const T = typeByKey.value
  for (const key in props.totals) {
    if (T[key] === 'money') {
      let sum = 0
      for (const r of filteredRows.value) { const n = parseFloat(r[key]); if (!isNaN(n)) sum += n }
      out[key] = Math.round(sum * 100) / 100
    } else {
      // текстовую метку итога помечаем, что она пересчитана по фильтру
      out[key] = String(props.totals[key]).indexOf('ИТОГО') !== -1 ? 'ИТОГО (фильтр):' : props.totals[key]
    }
  }
  return out
})

function totalDisplay(col) {
  const T = footerTotals.value
  if (!T || !(col.key in T)) return ''
  const v = T[col.key]
  if (col.type === 'money') {
    const n = Number(v)
    return isFinite(n) ? n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v
  }
  return v
}
const hasTotals = computed(() => props.totals && Object.keys(props.totals).length > 0)
</script>

<template>
  <div class="dt-wrap">
    <!-- Клиентские фильтры по датам -->
    <div v-if="dateCols.length" class="dt-filters">
      <div v-for="c in dateCols" :key="c.key" class="dt-filter">
        <span class="dt-filter-label">{{ c.title }}:</span>
        <input type="date" v-model="dateFilters[c.key].from" />
        <span class="dash">—</span>
        <input type="date" v-model="dateFilters[c.key].to" />
      </div>
      <button class="btn small" :disabled="!anyFilter" @click="resetFilters">Сбросить фильтры</button>
    </div>

    <div class="dt-toolbar">
      <button class="btn small" :disabled="!sorts.length" @click="resetSort">Сбросить сортировку</button>
      <span class="dt-hint" v-if="sorts.length">
        Сортировка: {{ sorts.map((s, i) => `${i + 1}. ${columns.find(c => c.key === s.key)?.title} ${s.dir === 'asc' ? '↑' : '↓'}`).join(', ') }}
      </span>
      <span class="dt-hint muted" v-else>Кликайте по заголовкам для сортировки (можно по нескольким полям)</span>
      <span class="dt-count">Показано {{ displayRows.length }} из {{ rows.length }}</span>
    </div>

    <div class="dt-scroll">
      <table class="dt">
        <thead>
          <tr>
            <th v-for="c in columns" :key="c.key" @click="toggleSort(c.key)" :class="'t-' + c.type">
              <span class="th-title">{{ c.title }}</span>
              <span v-if="sortState(c.key)" class="th-sort">
                {{ sortState(c.key).dir === 'asc' ? '↑' : '↓' }}<sup v-if="sorts.length > 1">{{ sortState(c.key).order }}</sup>
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <!-- _class строки — подсветка статуса (row-green/orange/red/…) от отчёта -->
          <tr v-for="(row, ri) in displayRows" :key="ri" :class="row._class || null">
            <td v-for="c in columns" :key="c.key" :class="'t-' + c.type">
              <a
                v-if="cellLink(row, c) && isInternal(cellLink(row, c))"
                :href="cellLink(row, c)"
                @click.prevent="emit('open-link', cellLink(row, c))"
              >{{ cellDisplay(row, c) }}</a>
              <a v-else-if="cellLink(row, c)" :href="cellLink(row, c)" target="_blank" rel="noopener">{{ cellDisplay(row, c) }}</a>
              <template v-else>{{ cellDisplay(row, c) }}</template>
            </td>
          </tr>
          <tr v-if="!displayRows.length" class="dt-empty">
            <td :colspan="columns.length">Нет данных</td>
          </tr>
        </tbody>
        <tfoot v-if="hasTotals">
          <tr>
            <td v-for="c in columns" :key="c.key" :class="'t-' + c.type">{{ totalDisplay(c) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>
