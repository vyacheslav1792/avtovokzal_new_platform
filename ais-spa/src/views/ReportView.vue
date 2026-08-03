<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { fetchMeta, runReport } from '../api'
import BackTicketView from './BackTicketView.vue'
import FiltersForm from '../components/FiltersForm.vue'
import ReportSection from '../components/ReportSection.vue'

const props = defineProps({ slug: String })

const meta = ref(null)
const result = ref(null)
const loading = ref(false)
const metaErr = ref('')
const runErr = ref('')

// Последние применённые фильтры — для перезапроса после закрытия оверлея.
let lastFilters = null

async function loadMeta(slug) {
  meta.value = null
  result.value = null
  metaErr.value = ''
  runErr.value = ''
  lastFilters = null
  try {
    const d = await fetchMeta(slug)
    meta.value = d.meta
    if (d.meta && d.meta.title) document.title = d.meta.title + ' · АИС'
  } catch (e) {
    metaErr.value = e.message
  }
}

async function onSubmit(filters) {
  lastFilters = filters
  loading.value = true
  runErr.value = ''
  try {
    result.value = await runReport(props.slug, filters)
  } catch (e) {
    runErr.value = e.message
    result.value = null
  } finally {
    loading.value = false
  }
}

function formatMoney(v) {
  const n = Number(v)
  if (!isFinite(n)) return v
  return n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// --- Оверлей для внутренних ссылок отчёта (возврат билета) ---
// Отчёт не сбрасывается; после закрытия данные перезапрашиваются с сервера
// (в оверлее могли выполнить возврат — статусы и суммы меняются).
const overlay = ref(null) // {path, params: URLSearchParams}

function onOpenLink(url) {
  const u = new URL(url, window.location.origin)
  if (u.pathname === '/back-ticket') {
    overlay.value = { path: u.pathname, params: u.searchParams }
  } else {
    window.open(url, '_blank', 'noopener')
  }
}

function closeOverlay() {
  overlay.value = null
  if (lastFilters) onSubmit(lastFilters)
}

function onKeydown(e) {
  if (e.key === 'Escape' && overlay.value) closeOverlay()
}
watch(overlay, (v) => {
  if (v) document.addEventListener('keydown', onKeydown)
  else document.removeEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))

watch(() => props.slug, (s) => { if (s) loadMeta(s) }, { immediate: true })
</script>

<template>
  <div>
    <h1 v-if="meta">{{ meta.title }}</h1>
    <div v-if="metaErr" class="alert">{{ metaErr }}</div>

    <FiltersForm v-if="meta" :filters="meta.filters" :loading="loading" @submit="onSubmit" />

    <div v-if="loading" class="muted loading">Формирование отчёта…</div>
    <div v-if="runErr" class="alert">{{ runErr }}</div>

    <template v-if="result">
      <div v-if="result.errors" class="alert warn" v-html="result.errors"></div>

      <div v-if="result.summary && result.summary.length" class="summary">
        <div v-for="(s, i) in result.summary" :key="i" class="summary-item">
          <div class="summary-label">{{ s.label }}</div>
          <div class="summary-value">{{ formatMoney(s.value) }}</div>
        </div>
      </div>

      <ReportSection
        v-for="sec in result.sections"
        :key="sec.id"
        :section="sec"
        @open-link="onOpenLink"
      />
    </template>

    <div v-if="overlay" class="overlay-backdrop" @click.self="closeOverlay">
      <div class="overlay-panel">
        <button class="overlay-close" title="Закрыть (Esc)" @click="closeOverlay">×</button>
        <BackTicketView
          v-if="overlay.path === '/back-ticket'"
          :key="overlay.params.toString()"
          :order-id="overlay.params.get('order_id') || ''"
          :serial="overlay.params.get('serial') || ''"
          :number="overlay.params.get('number') || ''"
          overlay
        />
      </div>
    </div>
  </div>
</template>
