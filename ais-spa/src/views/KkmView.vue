<script setup>
/**
 * Фискальный сервер (KkmServer): адрес, устройство, кассир.
 *
 * До 15.11.2026 эти настройки жили только в `kkm-api/config/kkm.php` и
 * переменных окружения — то есть правка файла и пересоздание контейнера.
 * Теперь вариантов может быть несколько (боевая ККТ, эмулятор, резервная
 * касса), и переключение — нажатием.
 *
 * **Ни одного действующего варианта = фискализация выключена:** чеки не
 * пробиваются вовсе. Это сознательный режим (например, на время замены
 * ККТ), а не поломка.
 */
import { computed, onMounted, ref } from 'vue'
import { activateKkmServer, fetchKkmServers, saveKkmServers, testKkmServer } from '../api'
import { can } from '../auth'

const servers = ref([])
const slots = ref(3)
const fromFile = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const results = ref({})

const editable = computed(() => can('kkm.edit'))
const testable = computed(() => can('kkm.test'))
const active = computed(() => servers.value.find((s) => s.is_active) ?? null)

/** Пустые слоты показываем тоже — иначе непонятно, куда добавлять вариант. */
function withEmptySlots(list) {
  return Array.from({ length: slots.value }, (_, i) => {
    const slot = i + 1
    const existing = list.find((s) => s.slot === slot)

    return existing
      ? { ...existing, password: '' }
      : {
          slot,
          label: '',
          is_active: false,
          url: '',
          user: '',
          password: '',
          has_password: false,
          num_device: 1,
          command_timeout: 30,
          cashier_name: '',
          notes: '',
        }
  })
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const d = await fetchKkmServers()
    slots.value = d.data.slots
    fromFile.value = d.data.from_file
    servers.value = withEmptySlots(d.data.servers)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  saved.value = false
  try {
    const d = await saveKkmServers(
      servers.value
        .filter((s) => (s.url ?? '').trim() !== '')
        .map((s) => ({
          slot: s.slot,
          label: s.label || `Вариант ${s.slot}`,
          url: s.url.trim(),
          user: s.user ?? '',
          password: s.password ?? '',
          num_device: Number(s.num_device) || 0,
          command_timeout: Number(s.command_timeout) || 30,
          cashier_name: s.cashier_name ?? '',
          notes: s.notes ?? '',
        })),
    )
    servers.value = withEmptySlots(d.data.servers)
    saved.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

/** slot = null выключает фискализацию целиком. */
async function activate(slot) {
  if (!editable.value) return

  if (slot === null
    && !window.confirm('Выключить фискализацию? Чеки перестанут пробиваться.')) {
    return
  }

  error.value = ''
  try {
    const d = await activateKkmServer(slot)
    servers.value = withEmptySlots(d.data.servers)
  } catch (e) {
    error.value = e.message
  }
}

async function check(slot) {
  error.value = ''
  try {
    const d = await testKkmServer(slot)
    results.value = { ...results.value, [slot]: d.data.result }
    servers.value = withEmptySlots(d.data.servers)
  } catch (e) {
    error.value = e.message
  }
}

onMounted(() => {
  document.title = 'Фискальный сервер · АИС'
  load()
})
</script>

<template>
  <div class="narrow">
    <h1>Фискальный сервер (ККМ)</h1>
    <p class="muted">
      Куда kkm-api отправляет фискальные чеки. Вариантов может быть
      {{ slots }} — действует один, переключение применяется сразу.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved" class="alert ok">Настройки сохранены.</div>

    <div v-if="fromFile" class="alert warn">
      Справочник пуст: kkm-api работает по своему файлу настроек
      (<code>config/kkm.php</code> и переменные окружения). Заполните вариант
      и сделайте его действующим — после этого настройки берутся отсюда.
    </div>

    <div v-else-if="!active" class="alert warn">
      Действующего варианта нет — <b>фискализация выключена</b>, чеки не
      пробиваются.
    </div>

    <div v-if="loading" class="muted">Загружаем настройки…</div>

    <template v-else>
      <div v-for="s in servers" :key="s.slot" class="card" :class="{ on: s.is_active }">
        <div class="card-head">
          <b>Вариант {{ s.slot }}<template v-if="s.label"> · {{ s.label }}</template></b>
          <span v-if="s.is_active" class="badge">действующий</span>
        </div>

        <div class="form-row">
          <label>Название<input v-model="s.label" type="text" placeholder="Боевая ККТ"></label>
          <label>
            Адрес KkmServer
            <input v-model="s.url" type="text" placeholder="http://127.0.0.1:5893">
          </label>
        </div>
        <div class="form-row">
          <label>Пользователь<input v-model="s.user" type="text"></label>
          <label>
            Пароль
            <input
              v-model="s.password" type="password"
              :placeholder="s.has_password ? '•••••• (задан)' : 'не задан'"
            >
          </label>
          <label>
            Устройство (NumDevice)
            <input v-model.number="s.num_device" type="number" min="0" max="255">
          </label>
          <label>
            Таймаут, с
            <input v-model.number="s.command_timeout" type="number" min="1" max="600">
          </label>
        </div>
        <div class="form-row">
          <label>Кассир в чеке<input v-model="s.cashier_name" type="text"></label>
          <label>Пояснение<input v-model="s.notes" type="text"></label>
        </div>

        <div class="card-foot">
          <button
            v-if="editable && !s.is_active" class="btn small"
            :disabled="!s.url" @click="activate(s.slot)"
          >
            Сделать действующим
          </button>
          <button
            v-if="testable" class="btn small" :disabled="!s.url"
            @click="check(s.slot)"
          >
            Проверить связь
          </button>

          <template v-if="results[s.slot]">
            <span :class="results[s.slot].ok ? 'ok' : 'fail'">
              {{ results[s.slot].ok ? 'ККТ отвечает' : 'нет связи' }}
            </span>
            <span v-if="results[s.slot].ok && results[s.slot].info" class="muted small">
              ККТ {{ results[s.slot].info.kkt || '—' }},
              ФН {{ results[s.slot].info.fn || '—' }},
              смена {{ results[s.slot].info.session_number ?? '—' }}
            </span>
            <span v-else-if="!results[s.slot].ok" class="muted small">
              {{ results[s.slot].error }}
            </span>
          </template>
          <span v-else-if="s.checked_at" class="muted small">
            проверяли {{ s.checked_at }}:
            <span :class="s.check_ok ? 'ok' : 'fail'">{{ s.check_ok ? 'ок' : 'нет связи' }}</span>
            {{ s.check_error || '' }}
          </span>
        </div>
      </div>

      <div class="actions">
        <button v-if="editable" class="btn primary" :disabled="saving" @click="save">
          Сохранить настройки
        </button>
        <button
          v-if="editable && active" class="btn"
          @click="activate(null)"
        >
          Выключить фискализацию
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.card {
  border: 1px solid #e5e5e5; border-radius: 6px; padding: 14px; margin-bottom: 14px;
}
.card.on { border-color: #1a7f37; }
.card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.card-foot { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
.badge {
  background: #1a7f37; color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 12px;
}
.form-row { display: flex; gap: 12px; flex-wrap: wrap; }
.form-row label { flex: 1 1 180px; }
label { display: block; margin-bottom: 8px; }
input[type="text"], input[type="number"], input[type="password"] {
  width: 100%; box-sizing: border-box;
}
.actions { display: flex; gap: 8px; }
.small { font-size: 12px; }
.ok { color: #1a7f37; font-weight: 600; }
.fail { color: #d0021b; font-weight: 600; }
</style>
