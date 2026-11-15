<script setup>
/**
 * Автостанции и их базы.
 *
 * До 15.11.2026 это был файл: `shared/stations.php` в монорепо, а в
 * legacy — массив `$AWT` прямо в коде, причём в трёх местах, и копии
 * разъехались. Переключение адресов делалось правкой `$IP_MODE` и
 * выкладкой кода на боевой сервер.
 *
 * Здесь у станции три выключателя (станция целиком, продажа, возврат) и
 * до трёх вариантов подключения с переключением одним нажатием. Правка
 * требует кода подтверждения: ошибка здесь останавливает продажу.
 */
import { computed, onMounted, ref } from 'vue'
import {
  fetchStations,
  importStationsFromFile,
  saveStation,
  switchStationProfile,
  testStation,
} from '../api'
import { can } from '../auth'

const stations = ref([])
const slots = ref(3)
const fromFile = ref(false)
const fileStations = ref([])
const loading = ref(false)
const error = ref('')
const busy = ref('')          // код станции, по которой идёт операция
const results = ref({})       // awtcod -> результат проверки связи

/** Открытая на правку станция (копия — чтобы «Отмена» ничего не меняла). */
const editing = ref(null)

const editable = computed(() => can('stations.edit'))
const testable = computed(() => can('stations.test'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const d = await fetchStations()
    stations.value = d.data.stations
    slots.value = d.data.profile_slots
    fromFile.value = d.data.from_file
    fileStations.value = d.data.file_stations ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function profileOf(station, slot) {
  return station.profiles.find((p) => p.slot === slot) ?? null
}

function address(profile) {
  return profile ? `${profile.db_host}:${profile.db_port}/${profile.db_name}` : '—'
}

/** Быстрое переключение: одно нажатие вместо правки и выкладки файла. */
async function switchTo(station, slot) {
  if (!editable.value || station.active_profile === slot) return

  busy.value = station.awtcod
  error.value = ''
  try {
    const d = await switchStationProfile(station.awtcod, slot)
    replace(d.data)
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

async function check(station, slot) {
  busy.value = station.awtcod
  error.value = ''
  try {
    const d = await testStation(station.awtcod, slot)
    results.value = { ...results.value, [station.awtcod]: d.data.result }
    replace(d.data.station)
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

/** Выключатели станции сохраняем сразу — это и есть их смысл. */
async function toggle(station, field) {
  if (!editable.value) return

  busy.value = station.awtcod
  error.value = ''
  try {
    const payload = {
      ...stationPayload(station),
      [field]: !station[field],
      // Профили в этом запросе не меняем — передаём как есть.
      profiles: station.profiles.map((p) => ({ ...p, db_password: '' })),
    }
    const d = await saveStation(station.awtcod, payload)
    replace(d.data)
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

function stationPayload(station) {
  return {
    name: station.name,
    buh: station.buh,
    buh_st: station.buh_st,
    buh_ksr: station.buh_ksr,
    stationkey: station.stationkey,
    inlist: station.inlist,
    is_active: station.is_active,
    sales_enabled: station.sales_enabled,
    refunds_enabled: station.refunds_enabled,
    active_profile: station.active_profile,
  }
}

function replace(station) {
  stations.value = stations.value.map((s) => (s.awtcod === station.awtcod ? station : s))
  if (editing.value?.awtcod === station.awtcod) editing.value = null
}

function edit(station) {
  // Пароли наружу не отдаются: пустое поле означает «оставить прежний».
  editing.value = {
    ...JSON.parse(JSON.stringify(station)),
    profiles: Array.from({ length: slots.value }, (_, i) => {
      const slot = i + 1
      const existing = profileOf(station, slot)

      return existing
        ? { ...existing, db_password: '' }
        : {
            slot,
            label: '',
            db_host: '',
            db_port: 3050,
            db_name: 'buster',
            db_user: 'SYSDBA',
            db_password: '',
            notes: '',
            has_password: false,
          }
    }),
  }
}

async function save() {
  busy.value = editing.value.awtcod
  error.value = ''
  try {
    const payload = {
      ...stationPayload(editing.value),
      // Пустой вариант (без адреса) означает «этого варианта нет».
      profiles: editing.value.profiles
        .filter((p) => (p.db_host ?? '').trim() !== '')
        .map((p) => ({
          slot: p.slot,
          label: p.label || `Вариант ${p.slot}`,
          db_host: p.db_host.trim(),
          db_port: Number(p.db_port) || 3050,
          db_name: p.db_name.trim(),
          db_user: p.db_user.trim(),
          db_password: p.db_password ?? '',
          notes: p.notes ?? '',
        })),
    }
    const d = await saveStation(editing.value.awtcod, payload)
    replace(d.data)
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

async function importFile() {
  if (!window.confirm('Перенести станции из файла конфигурации в справочник?')) return

  busy.value = 'import'
  error.value = ''
  try {
    const d = await importStationsFromFile()
    await load()
    window.alert(`Перенесено станций: ${d.data.imported}`)
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = ''
  }
}

onMounted(() => {
  document.title = 'Автостанции · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Автостанции</h1>
    <p class="muted">
      Подключения к базам продаж (BUSTER) и выключатели станций. Меняются сразу —
      выкладывать код не нужно. У станции может быть до {{ slots }} вариантов
      подключения: переключение — нажатием на номер варианта.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>

    <div v-if="fromFile" class="alert warn">
      Справочник пуст, поэтому сайт и отчёты работают по файлу конфигурации
      (<code>shared/stations.php</code>, станции:
      {{ fileStations.join(', ') || 'нет' }}).
      <button v-if="editable" class="btn" :disabled="busy === 'import'" @click="importFile">
        Перенести станции из файла
      </button>
    </div>

    <div v-if="loading" class="muted">Загружаем справочник…</div>

    <table v-else class="pages-table">
      <thead>
        <tr>
          <th>Код</th>
          <th>Станция</th>
          <th>Станция вкл.</th>
          <th>Продажа</th>
          <th>Возврат</th>
          <th>Подключение</th>
          <th>Связь</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in stations" :key="s.awtcod" :class="{ 'row-off': !s.is_active }">
          <td>{{ s.awtcod }}</td>
          <td>
            <b>{{ s.name }}</b>
            <div class="muted small">{{ s.buh || '—' }} · порядок {{ s.inlist }}</div>
          </td>
          <td>
            <!-- Отключённой станции для сайта и отчётов не существует вовсе. -->
            <label class="switch">
              <input
                type="checkbox" :checked="s.is_active" :disabled="!editable || busy === s.awtcod"
                @change="toggle(s, 'is_active')"
              >
              <span>{{ s.is_active ? 'да' : 'нет' }}</span>
            </label>
          </td>
          <td>
            <label class="switch">
              <input
                type="checkbox" :checked="s.sales_enabled"
                :disabled="!editable || busy === s.awtcod || !s.is_active"
                @change="toggle(s, 'sales_enabled')"
              >
              <span>{{ s.sales_enabled ? 'да' : 'нет' }}</span>
            </label>
          </td>
          <td>
            <label class="switch">
              <input
                type="checkbox" :checked="s.refunds_enabled"
                :disabled="!editable || busy === s.awtcod || !s.is_active"
                @change="toggle(s, 'refunds_enabled')"
              >
              <span>{{ s.refunds_enabled ? 'да' : 'нет' }}</span>
            </label>
          </td>
          <td>
            <div class="slots">
              <button
                v-for="n in slots" :key="n"
                class="slot"
                :class="{ on: s.active_profile === n, empty: !profileOf(s, n) }"
                :disabled="!editable || !profileOf(s, n) || busy === s.awtcod"
                :title="profileOf(s, n)
                  ? `${profileOf(s, n).label}: ${address(profileOf(s, n))}`
                  : 'Вариант не задан'"
                @click="switchTo(s, n)"
              >
                {{ n }}
              </button>
            </div>
            <div class="muted small">
              {{ profileOf(s, s.active_profile)?.label || 'подключение не задано' }}:
              {{ address(profileOf(s, s.active_profile)) }}
            </div>
          </td>
          <td>
            <template v-if="results[s.awtcod]">
              <span :class="results[s.awtcod].ok ? 'ok' : 'fail'">
                {{ results[s.awtcod].ok ? `ок ${results[s.awtcod].info?.ms ?? ''} мс` : 'нет связи' }}
              </span>
              <div v-if="!results[s.awtcod].ok" class="muted small">
                {{ results[s.awtcod].error }}
              </div>
            </template>
            <template v-else-if="profileOf(s, s.active_profile)?.checked_at">
              <span :class="profileOf(s, s.active_profile).check_ok ? 'ok' : 'fail'">
                {{ profileOf(s, s.active_profile).check_ok ? 'ок' : 'нет связи' }}
              </span>
              <div class="muted small">{{ profileOf(s, s.active_profile).checked_at }}</div>
            </template>
            <span v-else class="muted">не проверяли</span>
          </td>
          <td class="right nowrap">
            <button
              v-if="testable" class="btn small"
              :disabled="busy === s.awtcod || !profileOf(s, s.active_profile)"
              @click="check(s)"
            >
              Проверить
            </button>
            <button v-if="editable" class="btn small" @click="edit(s)">Настроить</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Правка станции: реквизиты и все варианты подключения сразу -->
    <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
      <div class="modal wide">
        <h2>{{ editing.name }} · {{ editing.awtcod }}</h2>

        <div class="form-row">
          <label>Название<input v-model="editing.name" type="text"></label>
          <label>Порядок<input v-model.number="editing.inlist" type="number" min="0"></label>
        </div>
        <div class="form-row">
          <label>Код для бухгалтерии<input v-model="editing.buh" type="text"></label>
          <label>Станция в документах<input v-model="editing.buh_st" type="text"></label>
          <label>Кассир в документах<input v-model="editing.buh_ksr" type="text"></label>
        </div>
        <label>Ключ станции (stationkey)<input v-model="editing.stationkey" type="text"></label>

        <h3>Варианты подключения</h3>
        <p class="muted small">
          Отличаться может всё: адрес, порт, имя базы, пользователь и пароль.
          Пустой адрес означает, что варианта нет. Пароль не показывается —
          пустое поле оставляет прежний.
        </p>

        <div v-for="p in editing.profiles" :key="p.slot" class="profile-card">
          <div class="profile-head">
            <b>Вариант {{ p.slot }}</b>
            <label class="radio">
              <input v-model.number="editing.active_profile" type="radio" :value="p.slot">
              действующий
            </label>
          </div>
          <div class="form-row">
            <label>Название<input v-model="p.label" type="text" placeholder="Через VPN"></label>
            <label>Адрес<input v-model="p.db_host" type="text" placeholder="192.168.4.250"></label>
            <label>Порт<input v-model.number="p.db_port" type="number" min="1"></label>
          </div>
          <div class="form-row">
            <label>База<input v-model="p.db_name" type="text" placeholder="buster"></label>
            <label>Пользователь<input v-model="p.db_user" type="text" placeholder="SYSDBA"></label>
            <label>
              Пароль
              <input
                v-model="p.db_password" type="password"
                :placeholder="p.has_password ? '•••••• (задан)' : 'не задан'"
              >
            </label>
          </div>
          <label>Пояснение<input v-model="p.notes" type="text"></label>
          <div class="profile-foot">
            <button
              v-if="testable" class="btn small" :disabled="!p.db_host"
              @click="check(editing, p.slot)"
            >
              Проверить этот вариант
            </button>
            <span v-if="p.checked_at" class="muted small">
              проверяли {{ p.checked_at }}:
              <span :class="p.check_ok ? 'ok' : 'fail'">{{ p.check_ok ? 'ок' : 'нет связи' }}</span>
              {{ p.check_error || '' }}
            </span>
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn primary" :disabled="busy === editing.awtcod" @click="save">
            Сохранить
          </button>
          <button class="btn" @click="editing = null">Отмена</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.row-off { opacity: .55; }
.small { font-size: 12px; }
.nowrap { white-space: nowrap; }
.right { text-align: right; }
.ok { color: #1a7f37; font-weight: 600; }
.fail { color: #d0021b; font-weight: 600; }

.slots { display: flex; gap: 4px; }
.slot {
  width: 28px; height: 28px; border: 1px solid #ccc; background: #fff;
  border-radius: 4px; cursor: pointer; font-weight: 600;
}
.slot.on { background: #d0021b; border-color: #d0021b; color: #fff; }
.slot.empty { color: #bbb; cursor: not-allowed; }

.switch { display: inline-flex; align-items: center; gap: 6px; }

.modal-backdrop {
  position: fixed; inset: 0; background: rgba(0, 0, 0, .4);
  display: flex; align-items: flex-start; justify-content: center; padding: 24px;
  overflow: auto; z-index: 50;
}
.modal {
  background: #fff; border-radius: 8px; padding: 20px; width: 100%; max-width: 560px;
}
.modal.wide { max-width: 880px; }
.modal-actions { display: flex; gap: 8px; margin-top: 16px; }

.form-row { display: flex; gap: 12px; flex-wrap: wrap; }
.form-row label { flex: 1 1 180px; }
label { display: block; margin-bottom: 8px; }
input[type="text"], input[type="number"], input[type="password"] {
  width: 100%; box-sizing: border-box;
}
.radio { display: inline-flex; align-items: center; gap: 6px; margin: 0; }

.profile-card {
  border: 1px solid #e5e5e5; border-radius: 6px; padding: 12px; margin-bottom: 12px;
}
.profile-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.profile-foot { display: flex; align-items: center; gap: 10px; }
</style>
