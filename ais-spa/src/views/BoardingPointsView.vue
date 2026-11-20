<script setup>
/**
 * Пункты посадки — остановки на трассе рейса, с которых сайт продаёт
 * билеты («Дросково» на рейсах Орёл–Ливны и Ливны–Орёл).
 *
 * Своей базы продаж у такого пункта нет: рейсы ведут ДРУГИЕ станции,
 * причём в разные стороны разные. Поэтому пункт описывается названием
 * для покупателя и привязками «станция + название остановки в её базе»;
 * куда уйдёт запрос, решают тарифы, а не сотрудник (разбор —
 * docs/BOARDING_POINTS.md).
 *
 * Кнопка «Проверить» ходит в живую базу станции, потому что САМА станция
 * об условиях продажи молчит: без тарифа от остановки процедура резерва
 * отвечает «ошибок нет» и бронирует место по нулевой цене, а при
 * запрещённой посадке не возражает вовсе. Увидеть это до появления
 * остановки на сайте можно только так.
 */
import { computed, onMounted, ref } from 'vue'
import {
  checkBoardingPointStation,
  createBoardingPoint,
  deleteBoardingPoint,
  fetchBoardingPoints,
  fetchStationStops,
  saveBoardingPoint,
} from '../api'
import { can } from '../auth'

const points = ref([])
const stations = ref([])
const loading = ref(false)
const busy = ref('')
const error = ref('')
const editing = ref(null)
const creating = ref(false)
/** Результаты проверки привязок открытого пункта: ключ «awtcod|остановка». */
const checks = ref({})
/** Подсказки названий остановок по станции. */
const stops = ref({})

const editable = computed(() => can('boarding-points.edit'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const d = await fetchBoardingPoints()
    points.value = d.data.points
    stations.value = d.data.stations
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function stationName(awtcod) {
  return stations.value.find((s) => s.awtcod === awtcod)?.name ?? String(awtcod)
}

function addPoint() {
  creating.value = true
  checks.value = {}
  editing.value = {
    id: null,
    slug: '',
    name: '',
    is_active: true,
    show_on_home: true,
    show_in_schedule: true,
    inlist: 0,
    note: '',
    stations: [],
  }
}

function edit(point) {
  creating.value = false
  checks.value = {}
  editing.value = JSON.parse(JSON.stringify(point))
}

function closeForm() {
  editing.value = null
  creating.value = false
  checks.value = {}
}

function addBinding() {
  editing.value.stations.push({
    awtcod: stations.value[0]?.awtcod ?? null,
    station_name: '',
    is_active: true,
    inlist: editing.value.stations.length,
    excluded_destinations: [],
  })
}

function removeBinding(index) {
  editing.value.stations.splice(index, 1)
}

/**
 * Имя пункта подсказывает ключ (slug): он уходит в адреса сайта и в
 * заказы, поэтому задаётся латиницей и у существующего пункта меняется
 * только осознанно — прежние ссылки после этого осиротеют.
 */
const TRANSLIT = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
  й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
  у: 'u', ф: 'f', х: 'h', ц: 'c', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '',
  э: 'e', ю: 'yu', я: 'ya',
}

function suggestSlug() {
  if (!creating.value || !editing.value) return
  editing.value.slug = editing.value.name
    .toLowerCase()
    .split('')
    .map((ch) => TRANSLIT[ch] ?? ch)
    .join('')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function checkKey(binding) {
  return `${binding.awtcod}|${binding.station_name}`
}

/** Проверка привязки по живой базе станции. */
async function check(binding) {
  if (!binding.awtcod || !binding.station_name.trim()) return

  const key = checkKey(binding)
  busy.value = `check:${key}`
  error.value = ''
  try {
    const d = await checkBoardingPointStation(binding.awtcod, binding.station_name.trim())
    checks.value = { ...checks.value, [key]: d.data }
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

/** Названия остановок станции — подсказка в поле привязки. */
async function loadStops(awtcod) {
  if (!awtcod || stops.value[awtcod]) return

  busy.value = `stops:${awtcod}`
  try {
    const d = await fetchStationStops(awtcod)
    stops.value = { ...stops.value, [awtcod]: d.data }
  } catch (e) {
    // Станция может быть недоступна — подсказки просто не будет, название
    // остановки сотрудник введёт руками и проверит кнопкой.
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

async function save() {
  busy.value = editing.value.id ?? 'new'
  error.value = ''
  try {
    const payload = {
      slug: editing.value.slug.trim(),
      name: editing.value.name.trim(),
      is_active: editing.value.is_active,
      show_on_home: editing.value.show_on_home,
      show_in_schedule: editing.value.show_in_schedule,
      inlist: Number(editing.value.inlist) || 0,
      note: editing.value.note || null,
      // Привязка без названия остановки — незаполненная строка формы.
      stations: editing.value.stations
        .filter((b) => b.awtcod && (b.station_name ?? '').trim() !== '')
        .map((b, i) => ({
          awtcod: Number(b.awtcod),
          station_name: b.station_name.trim(),
          is_active: b.is_active,
          inlist: Number(b.inlist) || i,
          excluded_destinations: b.excluded_destinations?.length ? b.excluded_destinations : null,
        })),
    }

    if (editing.value.id) {
      await saveBoardingPoint(editing.value.id, payload)
    } else {
      await createBoardingPoint(payload)
    }

    closeForm()
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

/**
 * Удаление пункта посадки.
 *
 * Прежние заказы не портятся: в заказе лежат и станция, и название
 * остановки, поэтому возврат и отчёты по уже купленным билетам работают
 * без справочника. Пропадает лишь возможность КУПИТЬ от этой остановки —
 * но чаще правильнее выключить пункт, тогда и ссылки останутся живыми.
 */
async function remove(point) {
  if (!editable.value) return

  const text = `Удалить пункт посадки «${point.name}»?`
    + '\nБилеты, уже купленные от этой остановки, останутся в силе —'
    + ' пропадёт только возможность покупать от неё новые.'
  if (!window.confirm(text)) return

  busy.value = point.id
  error.value = ''
  try {
    await deleteBoardingPoint(point.id)
    points.value = points.value.filter((p) => p.id !== point.id)
    if (editing.value?.id === point.id) closeForm()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

onMounted(() => {
  document.title = 'Пункты посадки · АИС'
  load()
})
</script>

<template>
  <div>
    <div class="page-head">
      <h1>Пункты посадки</h1>
      <button v-if="editable" class="btn primary" @click="addPoint">Добавить пункт</button>
    </div>
    <p class="muted">
      Остановки на трассе рейсов, с которых сайт продаёт билеты. Своей базы у
      такой остановки нет: рейсы ведут автостанции из привязок, и в разные
      стороны они разные — от Дросково в Ливны везёт рейс из базы Орла, а в
      Орёл — рейс из базы Ливен. Какой станции уйдёт запрос, сайт решает по
      тарифам, вручную это задавать не нужно.
    </p>
    <p class="muted">
      Чтобы продажа заработала, на станции должны быть заведены тарифы от этой
      остановки и разрешена посадка в расписаниях остановок рейсов. Кнопка
      «Проверить» показывает, что уже есть, а чего не хватает.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="loading" class="muted">Загружаем справочник…</div>

    <table v-else class="pages-table">
      <thead>
        <tr>
          <th>Пункт</th>
          <th>Показывать</th>
          <th>Обслуживают</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in points" :key="p.id" :class="{ 'row-off': !p.is_active }">
          <td>
            <b>{{ p.name }}</b>
            <div class="muted small">{{ p.slug }} · порядок {{ p.inlist }}</div>
            <div v-if="p.note" class="muted small">{{ p.note }}</div>
          </td>
          <td>
            <div class="small">
              главная: {{ p.show_on_home ? 'да' : 'нет' }}<br>
              расписание: {{ p.show_in_schedule ? 'да' : 'нет' }}
            </div>
          </td>
          <td>
            <div v-if="!p.stations.length" class="muted small">привязок нет — продажи не будет</div>
            <div v-for="b in p.stations" :key="b.id" class="small">
              {{ b.station_title }} · остановка «{{ b.station_name }}»
              <span v-if="!b.is_active" class="muted">(выключена)</span>
              <span v-if="b.excluded_destinations.length" class="muted">
                · не продаём: {{ b.excluded_destinations.join(', ') }}
              </span>
            </div>
          </td>
          <td class="nowrap">
            <button class="btn" @click="edit(p)">{{ editable ? 'Изменить' : 'Посмотреть' }}</button>
            <button
              v-if="editable" class="btn danger" :disabled="busy === p.id"
              @click="remove(p)"
            >
              Удалить
            </button>
          </td>
        </tr>
        <tr v-if="!points.length">
          <td colspan="4" class="muted">Пунктов посадки пока нет.</td>
        </tr>
      </tbody>
    </table>

    <div v-if="editing" class="card form">
      <h2>{{ creating ? 'Новый пункт посадки' : `Пункт посадки «${editing.name}»` }}</h2>

      <div class="grid">
        <label>
          Название для покупателя
          <input v-model="editing.name" :disabled="!editable" @blur="suggestSlug">
        </label>
        <label>
          Ключ (slug)
          <input v-model="editing.slug" :disabled="!editable" placeholder="droskovo">
          <span class="muted small">
            латиница и дефис; уходит в адреса сайта и в заказы — у существующего
            пункта менять только осознанно
          </span>
        </label>
        <label>
          Порядок в списке
          <input v-model="editing.inlist" type="number" min="0" :disabled="!editable">
        </label>
        <label>
          Примечание
          <input v-model="editing.note" :disabled="!editable">
        </label>
      </div>

      <div class="switches">
        <label class="switch">
          <input v-model="editing.is_active" type="checkbox" :disabled="!editable">
          <span>пункт включён</span>
        </label>
        <label class="switch">
          <input v-model="editing.show_on_home" type="checkbox" :disabled="!editable">
          <span>показывать в поиске на главной</span>
        </label>
        <label class="switch">
          <input v-model="editing.show_in_schedule" type="checkbox" :disabled="!editable">
          <span>показывать в расписании</span>
        </label>
      </div>

      <h3>Станции, которые ведут рейсы через остановку</h3>
      <p class="muted small">
        Название остановки должно совпадать с названием в базе станции
        (TRACES.STATNAME) — в разных базах оно может отличаться.
      </p>

      <div v-for="(b, i) in editing.stations" :key="i" class="binding">
        <div class="grid">
          <label>
            Автостанция
            <select v-model="b.awtcod" :disabled="!editable" @change="loadStops(b.awtcod)">
              <option v-for="s in stations" :key="s.awtcod" :value="s.awtcod">
                {{ s.name }} ({{ s.awtcod }})
              </option>
            </select>
          </label>
          <label>
            Остановка в базе станции
            <input
              v-model="b.station_name" :disabled="!editable" list="stop-names"
              placeholder="Дросково КП" @focus="loadStops(b.awtcod)"
            >
          </label>
          <label class="switch">
            <input v-model="b.is_active" type="checkbox" :disabled="!editable">
            <span>привязка включена</span>
          </label>
          <div class="nowrap">
            <button class="btn" :disabled="busy === `check:${checkKey(b)}`" @click="check(b)">
              Проверить
            </button>
            <button v-if="editable" class="btn danger" @click="removeBinding(i)">Убрать</button>
          </div>
        </div>

        <datalist id="stop-names">
          <option v-for="name in (stops[b.awtcod] || [])" :key="name" :value="name" />
        </datalist>

        <div v-if="checks[checkKey(b)]" class="check">
          <div v-if="checks[checkKey(b)].error" class="alert">
            {{ checks[checkKey(b)].error }}
          </div>
          <template v-else>
            <div :class="checks[checkKey(b)].ok ? 'ok' : 'alert warn'">
              {{ checks[checkKey(b)].ok
                ? 'Продажа от остановки настроена'
                : 'Продажа от остановки пока не заработает' }}
            </div>
            <div class="small">
              Остановка встречается в направлениях:
              <template v-if="checks[checkKey(b)].directions.length">
                {{ checks[checkKey(b)].directions.map((d) => `${d.napname} (код ${d.statcod})`).join(', ') }}
              </template>
              <template v-else>не найдена</template>
            </div>
            <div class="small">
              Билеты продаются до:
              <template v-if="checks[checkKey(b)].destinations.length">
                {{ checks[checkKey(b)].destinations.map((d) => d.name).join(', ') }}
              </template>
              <template v-else>тарифов от остановки нет</template>
            </div>
            <div class="small">
              Посадка разрешена в {{ checks[checkKey(b)].boarding.allowed }} расписаниях,
              запрещена в {{ checks[checkKey(b)].boarding.denied }}.
            </div>
            <div v-for="w in checks[checkKey(b)].warnings" :key="w" class="muted small">⚠ {{ w }}</div>
          </template>
        </div>
      </div>

      <button v-if="editable" class="btn" @click="addBinding">Добавить станцию</button>

      <div class="form-actions">
        <button v-if="editable" class="btn primary" :disabled="busy !== ''" @click="save">
          Сохранить
        </button>
        <button class="btn" @click="closeForm">Закрыть</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.form { margin-top: 1.5rem; }
.grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); align-items: end; }
.switches { display: flex; flex-wrap: wrap; gap: 1rem; margin: 0.75rem 0; }
.binding { border: 1px solid var(--border, #ddd); border-radius: 8px; padding: 0.75rem; margin-bottom: 0.75rem; }
.check { margin-top: 0.5rem; }
.ok { color: #0a7a2f; font-weight: 500; }
.form-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
.nowrap { white-space: nowrap; }
</style>
