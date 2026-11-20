<script setup>
/**
 * Обновление данных, которые сайт держит у себя, а не спрашивает у станции
 * на каждый запрос: регулярного расписания и пунктов назначения.
 *
 * Оба обновляются сами (расписание — ночью, пункты назначения в 07:00 и
 * 10:00 МСК), но правка графика или новая остановка нужна на сайте сразу —
 * для этого кнопка. В legacy для расписания она была
 * (`getrasp.php?action=raspisanie`), и с переездом потерялась.
 *
 * Неудачное обновление прежние данные НЕ стирает: на сайте лучше вчерашний
 * список, чем пустое «Куда». Поэтому у каждой станции видно и когда данные
 * сняты, и когда обновление выполнялось, и с какой ошибкой.
 */
import { computed, onMounted, ref } from 'vue'
import { fetchSyncStatus, runSync } from '../siteApi'
import { can } from '../auth'

const KINDS = [
  {
    key: 'schedules',
    what: 'schedules',
    title: 'Регулярное расписание',
    countLabel: 'Маршрутов',
    hint: 'Страница сайта «Расписание автобусов». Обновляется само каждую ночь в 04:15; '
      + 'обновите вручную, если на станции поправили график и расписание нужно на сайте сегодня.',
  },
  {
    key: 'destinations',
    what: 'destinations',
    title: 'Пункты назначения',
    countLabel: 'Пунктов',
    hint: 'Список «Куда» на форме поиска билетов. Обновляется само в 07:00 и 10:00; '
      + 'обновите вручную, если на станции добавили или убрали остановку.',
  },
]

const status = ref({ schedules: [], destinations: [], stations: [] })
const loading = ref(false)
const error = ref('')
/** Что сейчас обновляется: ключ раздела или пусто. */
const running = ref('')
/** Итог последнего прогона по разделам. */
const results = ref({})
/** Выбранная станция по разделам: пусто — обновлять все. */
const only = ref({ schedules: '', destinations: '' })

const stations = computed(() => status.value.stations || [])

async function load() {
  loading.value = true
  error.value = ''
  try {
    status.value = await fetchSyncStatus()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function run(kind) {
  running.value = kind.key
  error.value = ''
  results.value = { ...results.value, [kind.key]: null }
  try {
    const data = await runSync(kind.what, only.value[kind.key] || null)
    results.value = { ...results.value, [kind.key]: data }
    // Прогон возвращает состояние обоих кэшей — список обновляем из него.
    status.value = { ...status.value, schedules: data.schedules, destinations: data.destinations }
  } catch (e) {
    error.value = e.message
  } finally {
    running.value = ''
  }
}

function moment(value) {
  return value
    ? new Date(value).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
    : '—'
}

/** Давно ли обновлялось: сутки для расписания — норма, трое — уже нет. */
function stale(value) {
  if (!value) return true

  return Date.now() - new Date(value).getTime() > 3 * 24 * 3600 * 1000
}

onMounted(() => {
  document.title = 'Обновление данных · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Обновление данных сайта</h1>
    <p class="muted">
      Расписание и список пунктов назначения сайт хранит у себя, а не спрашивает у станции
      на каждый запрос. Обновляются они сами, но здесь это можно сделать немедленно.
      Рейсы, свободные места и цены на кнопки не влияют — они всегда живые.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <div v-for="kind in KINDS" :key="kind.key" class="panel">
        <h2>{{ kind.title }}</h2>
        <p class="hint">{{ kind.hint }}</p>

        <div class="row-actions sync-actions">
          <label class="field">
            <span class="field-label">Станция</span>
            <select v-model="only[kind.key]">
              <option value="">Все станции</option>
              <option v-for="s in stations" :key="s.awtcod" :value="s.awtcod">
                {{ s.name }}
              </option>
            </select>
          </label>
          <button
            v-if="can('data-sync.run')"
            type="button"
            class="btn primary"
            :disabled="running === kind.key"
            @click="run(kind)"
          >
            {{ running === kind.key ? 'Обновляю…' : 'Обновить сейчас' }}
          </button>
          <span v-if="running === kind.key" class="muted">
            станции опрашиваются по очереди, это может занять около минуты
          </span>
        </div>

        <div v-if="results[kind.key]" class="alert" :class="{ ok: !results[kind.key].failed }">
          Обновлено станций: {{ results[kind.key].total - results[kind.key].failed }}
          из {{ results[kind.key].total }}.
          <template v-if="results[kind.key].failed">
            Не ответили — прежние данные сохранены:
            <b>{{ results[kind.key].rows.filter((r) => r.error).map((r) => r.name).join(', ') }}</b>.
          </template>
        </div>

        <table class="pages-table">
          <thead>
            <tr>
              <th>Станция</th>
              <th>{{ kind.countLabel }}</th>
              <th>Данные сняты</th>
              <th>Последняя попытка</th>
              <th>Ошибка</th>
            </tr>
          </thead>
          <tbody>
            <!--
              Ключ — код станции ИЛИ название: у пункта посадки кода нет,
              и все пункты шли под одним ключом (сервер приводил null к
              нулю). Vue ругался на дубликаты и путал значения строк при
              перерисовке после обновления.
            -->
            <tr v-for="row in status[kind.key]" :key="row.awtcod ?? row.name">
              <td>
                {{ row.name }}
                <!--
                  У такого места синк ничего не обновляет, поэтому пустая
                  «последняя попытка» — настройка, а не сбой.
                -->
                <span v-if="row.auto_disabled" class="muted">
                  · автосборка выключена
                </span>
              </td>
              <td class="fm-num">{{ row.count }}</td>
              <td :class="{ 'sync-stale': stale(row.generated_at) }">{{ moment(row.generated_at) }}</td>
              <td>{{ moment(row.synced_at) }}</td>
              <td>
                <span v-if="row.sync_error" class="sync-error" :title="row.sync_error">
                  {{ row.sync_error.slice(0, 80) }}
                </span>
                <span v-else class="muted">—</span>
              </td>
            </tr>
            <tr v-if="!status[kind.key].length">
              <td colspan="5" class="muted">Данных пока нет — нажмите «Обновить сейчас»</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
