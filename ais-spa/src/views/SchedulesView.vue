<script setup>
/**
 * Расписание станций: ручное ведение для тех, у кого базы продаж больше
 * нет.
 *
 * Зачем: закрытая станция (касса снята, базы нет) билетов не продаёт, но
 * автобусы через неё идут — и людям нужно расписание. В legacy для таких
 * станций лежал готовый HTML-файл (`url/raspisanie/<slug>.html`), и его
 * правили текстовым редактором прямо на боевом сервере: разметка руками,
 * без проверок, с риском сломать вёрстку страницы.
 *
 * Здесь то же расписание набирается формой и ложится в те же данные, по
 * которым сайт рисует расписание станций с базой. Признак «ведётся
 * вручную» защищает его от ночного обновления из BUSTER.
 *
 * Расписание состоит из ДВУХ половин: собранная из баз (её обновляет
 * `schedule:sync` — здесь она только для просмотра) и добавленная
 * вручную. Вторую можно дописать любой станции или остановке: часть
 * автобусов в базах не видна (чужой перевозчик, рейс не заходит на
 * станции из привязок), а людям о них знать нужно — раньше такие рейсы
 * дописывали прямо в HTML-файл.
 */
import { computed, onMounted, ref } from 'vue'
import {
  deletePointSchedule,
  deleteSchedule,
  fetchPointSchedule,
  fetchSchedule,
  fetchSchedules,
  savePointSchedule,
  saveSchedule,
} from '../api'
import { can } from '../auth'

/*
 * Адрес САЙТА (VITE_SITE_URL в compose) — кнопка «На сайте» ведёт на его
 * страницу расписания, а не на адрес АИС: это разные приложения и разные
 * порты, и относительная ссылка открывала бы /raspisanie-avtobusov у
 * самой АИС. Так же сделано на страницах содержимого сайта
 * (PagesView, BlocksView, ContactsView).
 */
const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const DAY_TITLES = {
  mon: 'Пн', tue: 'Вт', wed: 'Ср', thu: 'Чт', fri: 'Пт', sat: 'Сб', sun: 'Вс',
}

const stations = ref([])
const days = ref(Object.keys(DAY_TITLES))
const loading = ref(false)
const busy = ref('')
const error = ref('')
const saved = ref('')

/** Открытая на правку станция: {awtcod, name, routes: [...], ...}. */
const editing = ref(null)

const editable = computed(() => can('schedules.edit'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const d = await fetchSchedules()
    stations.value = d.data.stations
    days.value = d.data.days
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

/*
 * Проверки «можно ли править эту строку» больше нет: править можно любую
 * — форма меняет ТОЛЬКО вручную добавленные маршруты, а собранные из баз
 * станций остаются как есть. Решает одно право на правку (`editable`).
 */

/** Строка списка — это пункт посадки, а не автостанция. */
function isPoint(row) {
  return !!row.boarding_point
}

/** Ключ строки списка: код станции или slug пункта посадки. */
function rowKey(row) {
  return row.boarding_point ?? row.awtcod
}

async function open(row) {
  busy.value = `open:${rowKey(row)}`
  error.value = ''
  saved.value = ''
  try {
    const d = isPoint(row)
      ? await fetchPointSchedule(row.boarding_point)
      : await fetchSchedule(row.awtcod)
    editing.value = {
      ...d.data,
      auto_routes: d.data.auto_routes || [],
      has_connection: row.has_connection,
      // Маршруты приводим к виду формы: departures как строки времени.
      routes: (d.data.routes || []).map((r) => ({
        route_name: r.route_name || '',
        route_number: r.route_number || '',
        route_full_name: r.route_full_name || '',
        carrier: r.carrier || '',
        schedule: r.schedule || '',
        days: Array.isArray(r.days) ? [...r.days] : [],
        fixed_dates: Array.isArray(r.fixed_dates) ? [...r.fixed_dates] : [],
        times: (r.departures || []).map((d2) => d2.time).join(', '),
      })),
    }
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

function close() {
  editing.value = null
}

function addRoute() {
  editing.value.routes.push({
    route_name: '',
    route_number: '',
    route_full_name: '',
    carrier: '',
    schedule: 'Каждый день',
    days: [...days.value],
    fixed_dates: [],
    times: '',
  })
}

function removeRoute(index) {
  editing.value.routes.splice(index, 1)
}

function moveRoute(index, delta) {
  const next = index + delta
  if (next < 0 || next >= editing.value.routes.length) return
  const routes = editing.value.routes
  ;[routes[index], routes[next]] = [routes[next], routes[index]]
}

function toggleDay(route, day) {
  const i = route.days.indexOf(day)
  if (i >= 0) route.days.splice(i, 1)
  else route.days.push(day)
}

/**
 * Времена отправления вводятся одной строкой («09:15, 10:35, 14:40») —
 * так их и переписывают с бумажного расписания. Разбираем и сортируем:
 * на странице сайта они идут по возрастанию.
 */
function parseTimes(raw) {
  return [...new Set(
    String(raw)
      .split(/[\s,;]+/)
      .map((t) => t.trim())
      .filter(Boolean)
      .map((t) => {
        const m = t.match(/^(\d{1,2})[:.](\d{2})$/)
        return m ? `${m[1].padStart(2, '0')}:${m[2]}` : t
      }),
  )].sort()
}

/** Что не так в форме — проверяем до отправки, чтобы не ловить 422. */
const problems = computed(() => {
  if (!editing.value) return []

  const list = []
  editing.value.routes.forEach((r, i) => {
    const n = i + 1
    if (!r.route_name.trim()) list.push(`Маршрут ${n}: укажите название.`)
    if (!r.schedule.trim()) list.push(`Маршрут ${n}: укажите график следования.`)

    const times = parseTimes(r.times)
    if (!times.length) list.push(`Маршрут ${n}: добавьте хотя бы одно время отправления.`)
    times.forEach((t) => {
      if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(t)) list.push(`Маршрут ${n}: «${t}» — не время (нужно ЧЧ:ММ).`)
    })
  })

  return list
})

async function save() {
  if (problems.value.length) return

  busy.value = `save:${editing.value.boarding_point ?? editing.value.awtcod}`
  error.value = ''
  saved.value = ''
  try {
    const payload = {
      note: editing.value.note || null,
      auto_disabled: !!editing.value.auto_disabled,
      routes: editing.value.routes.map((r) => ({
        route_name: r.route_name.trim(),
        route_number: r.route_number?.trim() || null,
        route_full_name: r.route_full_name?.trim() || null,
        carrier: r.carrier?.trim() || null,
        schedule: r.schedule.trim(),
        days: r.days,
        fixed_dates: r.fixed_dates,
        departures: parseTimes(r.times).map((time) => ({ time })),
      })),
    }

    const d = editing.value.boarding_point
      ? await savePointSchedule(editing.value.boarding_point, payload)
      : await saveSchedule(editing.value.awtcod, payload)
    saved.value = `Сохранено: добавлено вручную ${d.data.manual_count},`
      + ` из баз станций ${d.data.auto_count}.`
    close()
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

/**
 * Убрать маршруты, добавленные вручную. Собранные из баз остаются —
 * удалить их отсюда нельзя, их вернёт следующее обновление.
 *
 * Условие — именно `manual_count`, как и у кнопки. Раньше здесь стоял
 * `row.is_manual`, а этот признак сброшен миграцией и в ответе для
 * станций его нет вовсе: кнопка показывалась и молча ничего не делала.
 */
async function remove(row) {
  if (!editable.value || !row.manual_count) return

  const text = `Убрать у «${row.name}» маршруты, добавленные вручную (${row.manual_count})?`
    + '\n\nСобранные из баз станций останутся на месте,'
    + ' страница расписания продолжит открываться.'
  if (!window.confirm(text)) return

  busy.value = `del:${rowKey(row)}`
  error.value = ''
  try {
    if (isPoint(row)) await deletePointSchedule(row.boarding_point)
    else await deleteSchedule(row.awtcod)
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

/**
 * Адрес страницы расписания на сайте — чтобы проверить глазами.
 *
 * В адресе НАЗВАНИЕ станции, как было и в legacy (`?from=Орел АВ`):
 * латинский `slug` там был именем файла расписания, а не адресом, и
 * второй адрес на ту же страницу сайту не нужен — сайт с него всё равно
 * уводит редиректом на канонический.
 */
function siteLink(row) {
  return `${SITE_URL}/raspisanie-avtobusov?from=${encodeURIComponent(row.name)}`
}

onMounted(() => {
  document.title = 'Расписание станций · АИС'
  load()
})
</script>

<template>
  <div>
    <div class="page-head">
      <h1>Расписание станций</h1>
    </div>
    <p class="muted">
      Расписание станций с работающей базой приходит из BUSTER само (ночное
      обновление и кнопка на странице «Обновление данных») — его здесь только
      видно. Вручную ведут расписание закрытых станций: касса снята, база
      продаж не работает, а автобусы через станцию идут и расписание людям
      нужно. Раньше для них правили HTML-файл на сервере.
    </p>
    <p class="muted">
      Чтобы страница расписания закрытой станции открывалась на сайте, у
      станции в справочнике должен стоять признак «Показывать в расписании».
      Ручное расписание ночное обновление не трогает.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved" class="alert ok">{{ saved }}</div>
    <div v-if="loading" class="muted">Загружаем список…</div>

    <table v-else class="pages-table">
      <thead>
        <tr>
          <th>Станция</th>
          <th>Источник</th>
          <th>Маршрутов</th>
          <th>Обновлено</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in stations" :key="rowKey(s)" :class="{ 'row-off': !s.show_in_schedule }">
          <td>
            <b>{{ s.name }}</b>
            <div class="muted small">
              <span v-if="isPoint(s)">пункт посадки · {{ s.boarding_point }}</span>
              <span v-else>код {{ s.awtcod }}</span>
              <span v-if="!s.is_active"> · {{ isPoint(s) ? 'пункт выключен' : 'станция выключена' }}</span>
              <span v-if="!s.show_in_schedule"> · скрыт из расписания</span>
            </div>
            <div v-if="s.note" class="muted small">{{ s.note }}</div>
          </td>
          <td>
            <span v-if="s.auto_disabled" class="muted">автосборка выключена</span>
            <template v-else>
              <span v-if="s.auto_count">из баз: {{ s.auto_count }}</span>
              <span v-else class="muted">из баз: —</span>
            </template>
            <span v-if="s.manual_count"> · вручную: {{ s.manual_count }}</span>
            <div v-if="s.edited_by" class="muted small">правил: {{ s.edited_by }}</div>
          </td>
          <td>{{ s.routes_count || '—' }}</td>
          <td>
            <div class="small">{{ s.generated_at || '—' }}</div>
            <div v-if="s.sync_error" class="muted small">ошибка: {{ s.sync_error }}</div>
          </td>
          <td class="nowrap">
            <button
              class="btn" :disabled="busy === `open:${rowKey(s)}`"
              title="Открыть расписание: собранное из баз — для просмотра, вручную добавленное — на правку"
              @click="open(s)"
            >
              {{ editable ? 'Изменить' : 'Посмотреть' }}
            </button>
            <a class="btn" :href="siteLink(s)" target="_blank" rel="noopener">На сайте</a>
            <!--
              «Убрать» без уточнения читалось как «убрать расписание с
              сайта целиком» — а удаляются только ручные маршруты.
            -->
            <button
              v-if="editable && s.manual_count > 0" class="btn danger"
              :disabled="busy === `del:${rowKey(s)}`"
              :title="`Удалить ${s.manual_count} маршрутов, добавленных вручную;`
                + ' собранные из баз станций останутся'"
              @click="remove(s)"
            >
              Убрать ручные ({{ s.manual_count }})
            </button>
          </td>
        </tr>
        <tr v-if="!stations.length">
          <td colspan="5" class="muted">Станций в справочнике нет.</td>
        </tr>
      </tbody>
    </table>

    <div v-if="editing" class="card form">
      <h2>Расписание · {{ editing.name }}</h2>
      <p class="muted small">
        Адрес на сайте: <code>/raspisanie-avtobusov?from={{ editing.name }}</code>
      </p>

      <!--
        Половина, собранная из баз станций: только для просмотра — её
        перезапишет следующее обновление расписаний.
      -->
      <details v-if="editing.auto_routes.length" class="auto-routes">
        <summary>
          Из баз станций: {{ editing.auto_routes.length }} маршрутов (обновляются сами)
        </summary>
        <div v-for="(r, i) in editing.auto_routes" :key="`auto-${i}`" class="auto-row small">
          <b>{{ r.route_name }}</b>
          <span v-if="r.carrier"> · {{ r.carrier }}</span>
          <span class="muted"> · {{ r.schedule }}</span>
          <span> · {{ (r.departures || []).map((d) => d.time).join(', ') }}</span>
        </div>
      </details>
      <p v-else-if="editing.auto_disabled" class="muted small">
        Автосборка выключена: на сайте показываются только маршруты,
        добавленные вручную.
      </p>
      <p v-else class="muted small">
        Из баз станций по этому месту расписание не собралось: либо своей базы нет,
        либо через остановку не проходит ни один рейс регулярного расписания.
      </p>

      <h3>Добавленные вручную</h3>
      <p class="muted small">
        Здесь дописывают рейсы, которых в базах нет: чужой перевозчик, автобус,
        не заходящий на станции из привязок. Обновление расписаний их не трогает.
      </p>

      <!--
        Выключатель автосборки: нужен, когда база отдаёт расписание,
        которому нельзя верить (станция закрылась, а рейсы в ней остались).
        Тогда на сайте показываются только маршруты, добавленные вручную.
      -->
      <label class="switch block">
        <input v-model="editing.auto_disabled" type="checkbox" :disabled="!editable">
        <span>не собирать расписание из баз станций (вести только вручную)</span>
      </label>

      <label class="block">
        Примечание для сотрудников
        <input
          v-model="editing.note" :disabled="!editable"
          placeholder="например: станция закрыта 01.09.2026, расписание ведём вручную"
        >
      </label>

      <div v-for="(r, i) in editing.routes" :key="i" class="route">
        <div class="route-head">
          <b>Маршрут {{ i + 1 }}</b>
          <div class="nowrap">
            <button class="btn" :disabled="!editable || i === 0" @click="moveRoute(i, -1)">↑</button>
            <button
              class="btn" :disabled="!editable || i === editing.routes.length - 1"
              @click="moveRoute(i, 1)"
            >↓</button>
            <button v-if="editable" class="btn danger" @click="removeRoute(i)">Удалить</button>
          </div>
        </div>

        <div class="grid">
          <label>
            Название маршрута
            <input v-model="r.route_name" :disabled="!editable" placeholder="АВ Орел-Ливны">
          </label>
          <label>
            Номер маршрута
            <input v-model="r.route_number" :disabled="!editable" placeholder="511">
          </label>
          <label>
            Перевозчик
            <input v-model="r.carrier" :disabled="!editable" placeholder="ИП Чулыгин">
          </label>
          <label>
            График (как увидит пассажир)
            <input v-model="r.schedule" :disabled="!editable" placeholder="Каждый день">
          </label>
        </div>

        <div class="days">
          <span class="muted small">Дни следования:</span>
          <label v-for="d in days" :key="d" class="day">
            <input
              type="checkbox" :checked="r.days.includes(d)" :disabled="!editable"
              @change="toggleDay(r, d)"
            >
            <span>{{ DAY_TITLES[d] }}</span>
          </label>
        </div>

        <label class="block">
          Время отправления (через запятую)
          <input v-model="r.times" :disabled="!editable" placeholder="09:15, 10:35, 14:40">
          <span class="muted small">
            Сохраняется по возрастанию; формат ЧЧ:ММ. Ссылок на тариф у таких
            рейсов нет — в базе продаж их не существует.
          </span>
        </label>
      </div>

      <button v-if="editable" class="btn" @click="addRoute">Добавить маршрут</button>

      <div v-if="problems.length" class="alert warn">
        <div v-for="p in problems" :key="p">{{ p }}</div>
      </div>

      <div class="form-actions">
        <button
          v-if="editable" class="btn primary"
          :disabled="busy !== '' || problems.length > 0" @click="save"
        >
          Сохранить
        </button>
        <button class="btn" @click="close">Закрыть</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.form { margin-top: 1.5rem; }
.grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); align-items: end; }
.auto-routes { margin: 0.75rem 0; }
.auto-row { padding: 0.15rem 0; }
.route { border: 1px solid var(--border, #ddd); border-radius: 8px; padding: 0.75rem; margin: 0.75rem 0; }
.route-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.days { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; margin: 0.75rem 0; }
.day { display: inline-flex; align-items: center; gap: 0.25rem; }
.block { display: block; margin: 0.75rem 0; }
.form-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
.nowrap { white-space: nowrap; }
.ok { border-color: #0a7a2f; color: #0a7a2f; }
</style>
