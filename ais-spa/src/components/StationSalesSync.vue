<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { fetchStationSalesSync, startStationSalesSync } from '../api'
import { can, impersonated } from '../auth'

const emit = defineEmits(['updated'])
const data = ref(null)
const error = ref('')
const notice = ref('')
const submitting = ref(false)
const selected = ref([])
const customPeriod = ref(false)
const date1 = ref('')
const date2 = ref('')
let timer
let disposed = false
let initialized = false
let completedIds = new Set()

const stations = computed(() => data.value?.stations || [])
const allSelected = computed(() => stations.value.length > 0 && selected.value.length === stations.value.length)
const canRefresh = computed(() => can('report:station-sales.refresh') && !impersonated.value)
const labels = { queued: 'В очереди', running: 'Обновляется', completed: 'Завершено', failed: 'Ошибка' }

const overallStatus = computed(() => {
  const list = stations.value
  if (!data.value) return 'Загрузка…'
  if (!list.length) return 'Нет активных станций'
  if (list.some(s => s.run?.status === 'failed' || s.run?.error)) return labels.failed
  if (list.some(s => s.run?.status === 'running')) return labels.running
  if (list.some(s => s.run?.status === 'queued')) return labels.queued
  if (list.some(s => !s.run)) return 'Есть станции без обновления'
  return labels.completed
})
const oldest = computed(() => {
  if (!stations.value.length || stations.value.some(s => !s.current_updated_at)) return null
  return stations.value.reduce((a, b) => a.current_updated_at <= b.current_updated_at ? a : b)
})

function toggleAll() {
  selected.value = allSelected.value ? [] : stations.value.map((s) => s.code)
}

function date(value, time = false) {
  if (!value) return 'ещё не обновлялась'
  // API отдаёт стену Москвы без offset, не переинтерпретируем её в пояс браузера.
  const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?/)
  return match ? `${match[3]}.${match[2]}.${match[1]}${time && match[4] ? ' ' + match[4] : ''}` : value
}

function period(from, to) { return `${date(from)} — ${date(to)}` }

async function load() {
  try {
    const next = await fetchStationSalesSync()
    if (disposed) return
    const done = new Set(next.stations.filter((s) => s.run?.status === 'completed').map((s) => s.run.id))
    // После публикации периода обновляем открытый отчёт.
    const changed = initialized && [...done].some(id => !completedIds.has(id))
    data.value = next
    completedIds = done
    error.value = ''
    if (!initialized) {
      selected.value = next.stations.map((s) => s.code)
      date1.value = next.period.date1
      date2.value = next.period.date2
      initialized = true
    }
    if (changed) emit('updated')
  } catch (e) {
    if (!disposed) error.value = e.message
  } finally {
    if (!disposed) { clearTimeout(timer); timer = setTimeout(load, 5000) }
  }
}

async function start(event) {
  const station = event.submitter?.dataset.station
  submitting.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await startStationSalesSync({
      stations: station ? [Number(station)] : [...selected.value],
      ...(customPeriod.value ? { date1: date1.value, date2: date2.value } : {}),
    })
    if (disposed) return
    notice.value = `Поставлено в очередь станций: ${result.run_ids.length}. Период: ${period(result.date1, result.date2)}.`
    if (result.busy_stations.length) notice.value += ` Уже обновляются: ${result.busy_stations.length}.`
    clearTimeout(timer)
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    submitting.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => { disposed = true; clearTimeout(timer) })
</script>

<template>
  <details class="sales-sync panel">
    <summary>
      <strong>Данные автостанций</strong>
      <span :class="{ 'sync-error': error || overallStatus === labels.failed }">{{ error ? 'Ошибка получения данных' : overallStatus }}</span>
      <span v-if="stations.length" class="muted">
        {{ oldest ? 'Актуальность на ' + date(oldest.current_updated_at, true) + ' МСК · ' + oldest.name : 'Общая актуальность ещё не определена' }}
      </span>
    </summary>
    <p class="muted">
      Данные сайта — в реальном времени. Расписание фонового сбора по станциям задаётся в настройках.
      Время ниже — московское. «Актуальность» показывает начало последнего успешно собранного текущего окна;
      пересчёт прошлого периода её не меняет.
    </p>
    <p v-if="data" class="muted">
      Текущее окно: {{ period(data.period.date1, data.period.date2) }}
      ({{ data.period.lookback_days }} дн., включая сегодня). Порция до {{ data.period.chunk_days }} дн.
      <RouterLink v-if="can('settings.view')" to="/settings">Настройки сбора</RouterLink>.
      Для ведомости за старые даты могут потребоваться продажи, сделанные до начала отчётного периода.
    </p>
    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="notice" class="alert ok">{{ notice }}</div>
    <form id="station-sales-refresh" v-if="canRefresh && data" class="sync-actions nonprint" @submit.prevent="start">
      <label class="check"><input v-model="customPeriod" type="checkbox"> Указать свой период продаж</label>
      <div v-if="customPeriod" class="filters-grid">
        <label class="field"><span class="field-label">С даты продажи</span><input v-model="date1" type="date" required :max="date2 || data.period.date2"></label>
        <label class="field"><span class="field-label">По дату продажи включительно</span><input v-model="date2" type="date" required :min="date1" :max="data.period.date2"></label>
      </div>
      <button class="btn primary" :disabled="submitting || !selected.length">
        {{ submitting ? 'Постановка в очередь…' : `Обновить выбранные станции (${selected.length})` }}
      </button>
      <span class="muted">Без своего периода обновляется текущее окно из настроек. Можно закрыть страницу — сбор продолжится.</span>
    </form>
    <div v-if="stations.some((s) => !s.current_covers_window)" class="alert warn">
      По части станций текущее окно ещё не собрано полностью. Суммы станций в отчёте могут быть неполными.
    </div>
    <div class="sales-sync-table">
      <table>
        <thead><tr>
          <th v-if="canRefresh" class="nonprint"><input type="checkbox" :checked="allSelected" aria-label="Выбрать все станции" @change="toggleAll"></th>
          <th>Станция</th><th>Актуальность текущего окна</th><th>Последний успешно обновлённый период</th><th>Последнее задание</th><th v-if="canRefresh" class="nonprint">Действие</th>
        </tr></thead>
        <tbody><tr v-for="station in stations" :key="station.code">
          <td v-if="canRefresh" class="nonprint"><input v-model="selected" type="checkbox" :value="station.code" :aria-label="`Обновить ${station.name}`"></td>
          <td>{{ station.name }}</td>
          <td>
            {{ date(station.current_updated_at, true) }}
            <div v-if="station.current_from" class="muted">{{ period(station.current_from, station.current_to) }}</div>
          </td>
          <td>
            <template v-if="station.last_success">
              {{ period(station.last_success.date1, station.last_success.date2) }}
              <div class="muted">завершён {{ date(station.last_success.finished_at, true) }}</div>
            </template>
            <span v-else class="muted">нет завершённых обновлений</span>
          </td>
          <td>
            <template v-if="station.run">
              <b :class="{ 'sync-error': station.run.status === 'failed' }">{{ labels[station.run.status] }}</b>
              · {{ station.run.is_current ? 'текущее окно' : 'свой период' }}
              <div class="muted">{{ period(station.run.date1, station.run.date2) }} · дней прочитано {{ station.run.days_done }} из {{ station.run.days_total }} · загружено строк {{ station.run.rows_read }}</div>
              <div v-if="station.run.status === 'running'" class="muted">Сбор за период. Последний шаг: {{ date(station.run.updated_at, true) }}</div>
              <div v-if="station.run.error" class="sync-error">{{ station.run.error }}</div>
            </template>
            <span v-else class="muted">не запускалось</span>
          </td>
          <td v-if="canRefresh" class="nonprint">
            <button type="submit" form="station-sales-refresh" class="btn" :data-station="station.code"
              :aria-label="'Обновить станцию ' + station.name"
              :disabled="submitting || ['queued', 'running'].includes(station.run?.status)">Обновить</button>
          </td>
        </tr></tbody>
      </table>
    </div>
  </details>
</template>

<style scoped>
.sales-sync { margin-bottom: 18px; }
summary { cursor: pointer; }
summary > * { margin-inline-end: 12px; }
.sync-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 16px 0; }
.sales-sync-table { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid var(--line); padding: 8px; text-align: left; vertical-align: top; }
th { background: var(--head); }
.sync-error { color: #b42318; }
@media print { .sync-actions, .nonprint { display: none; } }
</style>
