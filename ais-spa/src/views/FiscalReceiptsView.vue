<script setup>
/**
 * Непробитые фискальные чеки за период.
 *
 * Чек ставится в очередь `receipts` и пробивается пятью попытками по
 * минуте. Если ККТ в это время недоступна (нет связи, закрытая смена,
 * кончилась бумага), попытки заканчиваются — и операция остаётся без
 * фискального документа, хотя деньги прошли. Раньше это можно было
 * заметить только глазами в отчёте продаж.
 *
 * Операции, перенесённые со старого сайта, здесь НЕ показываются к
 * пробитию: чеки по ним пробивала прежняя система, и второй фискальный
 * документ по давно закрытой продаже выдавать нельзя. Их число видно
 * отдельной строкой, чтобы цифры сходились.
 */
import { computed, onMounted, ref } from 'vue'
import { fetchFiscalPending, retryFiscal } from '../siteApi'
import { can } from '../auth'

const today = new Date().toISOString().slice(0, 10)
const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10)

const dateFrom = ref(weekAgo)
const dateTo = ref(today)

const data = ref(null)
const results = ref(null)
const loading = ref(false)
const running = ref(false)
const error = ref('')
const notice = ref('')

const canRetry = computed(() => can('kkm.receipts'))
const items = computed(() => [...(data.value?.orders || []), ...(data.value?.refunds || [])])
const skipped = computed(() => {
  const s = data.value?.skipped_legacy
  return s ? s.orders + s.refunds : 0
})

async function load() {
  loading.value = true
  error.value = ''
  results.value = null
  try {
    data.value = await fetchFiscalPending(dateFrom.value, dateTo.value)
  } catch (e) {
    error.value = e.message
    data.value = null
  } finally {
    loading.value = false
  }
}

async function run() {
  const ok = window.confirm(
    `Пробить чеки по ${items.value.length} операци(ям) за ${dateFrom.value} — ${dateTo.value}?\n\n`
    + 'ККТ выдаст настоящие фискальные документы. Повторный чек по той же операции '
    + 'не пробьётся — ключ идемпотентности не даст. Операции, перенесённые со старого '
    + 'сайта, не затрагиваются.',
  )
  if (!ok) return

  running.value = true
  error.value = ''
  notice.value = ''
  try {
    results.value = await retryFiscal(dateFrom.value, dateTo.value)
    notice.value = results.value.message
    await load()
    // load() сбрасывает результаты — возвращаем их, они нужны на экране.
    results.value = results.value
  } catch (e) {
    error.value = e.message
  } finally {
    running.value = false
  }
}

function money(v) {
  return Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 })
}

onMounted(() => {
  document.title = 'Непробитые чеки · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Непробитые чеки</h1>
    <p class="muted">
      Операции, по которым деньги прошли, а фискального чека нет. Чек пробивается
      из очереди пятью попытками по минуте — если в это время ККТ была недоступна
      (нет связи, закрытая смена, кончилась бумага), операция остаётся без чека.
      Здесь их можно пробить заново: ККТ выдаст настоящие фискальные документы,
      а повторный чек по той же операции не пройдёт — не даст ключ идемпотентности.
    </p>

    <div class="fb-filters">
      <label class="fb-field">
        <span class="fb-label">Период с</span>
        <input v-model="dateFrom" type="date" />
      </label>
      <label class="fb-field">
        <span class="fb-label">по</span>
        <input v-model="dateTo" type="date" />
      </label>
      <button class="btn" :disabled="loading || running" @click="load">Проверить</button>
      <button
        v-if="canRetry"
        class="btn primary"
        :disabled="loading || running || !items.length"
        @click="run"
      >
        {{ running ? 'Пробиваем…' : `Пробить чеки (${items.length})` }}
      </button>
    </div>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="notice" class="alert ok">{{ notice }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="data && !loading">
      <p v-if="!items.length" class="muted">
        За этот период непробитых чеков нет.
        <template v-if="skipped">
          Не учтено {{ skipped }} операц(ий), перенесённых со старого сайта — чеки по ним
          пробивала прежняя система.
        </template>
      </p>

      <template v-else>
        <p>
          Без фискального чека: <b>{{ items.length }}</b>
          (покупок {{ data.orders.length }}, возвратов {{ data.refunds.length }}).
          <template v-if="skipped">
            <br /><span class="muted">
              Плюс {{ skipped }} перенесённых со старого сайта — их не трогаем:
              чеки по ним пробивала прежняя система.
            </span>
          </template>
          <template v-if="items.length > data.limit">
            <br /><span class="muted">За один раз обрабатываем не больше {{ data.limit }}.</span>
          </template>
        </p>

        <table class="fb-table">
          <thead>
            <tr>
              <th>Когда</th>
              <th>Операция</th>
              <th class="num">Сумма</th>
              <th>Покупатель</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in items" :key="row.kind + row.id">
              <td class="nowrap">{{ row.created_at }}</td>
              <td>{{ row.title }}</td>
              <td class="num">{{ money(row.amount) }}</td>
              <td class="muted">{{ row.email || '—' }}</td>
              <td class="pages-actions">
                <router-link class="btn small" :to="row.refund_url">Карточка</router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </template>

    <!-- Итог прогона: по каждой операции видно, что получилось -->
    <section v-if="results && results.results && results.results.length" class="panel">
      <h2>Результат фискализации</h2>
      <p>
        Обработано: <b>{{ results.total }}</b> · пробито: <b>{{ results.ok }}</b> ·
        не удалось: <b>{{ results.failed }}</b>
      </p>
      <table class="fb-table">
        <thead>
          <tr>
            <th>Операция</th>
            <th class="num">Сумма</th>
            <th>Итог</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in results.results" :key="i" :class="r.ok ? 'fr-ok' : 'fr-fail'">
            <td>
              {{ r.title }}
              <br /><span class="muted small">{{ r.created_at }}</span>
            </td>
            <td class="num">{{ money(r.amount) }}</td>
            <td>
              <template v-if="r.ok">
                <span class="badge-on">чек пробит</span>
                <br /><span class="muted small">ФП {{ r.fiscal_sign }}</span>
              </template>
              <template v-else>
                <span class="badge-off">не удалось</span>
                <br /><span class="muted small">{{ r.error }}</span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<style scoped>
.fb-field input {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; width: 100%;
}
.num { text-align: right; }
.small { font-size: 11px; }
/* Итог прогона: удачные и неудачные видно сразу, без вчитывания. */
.fr-ok td { background: #e9ffe9; }
.fr-fail td { background: #ffecec; }
</style>
