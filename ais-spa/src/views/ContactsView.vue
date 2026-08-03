<script setup>
// Контакты сайта — редактирование данных страницы avtovokzal57 /contacts.
// В legacy эта страница была статическим HTML (url/contacts.php): телефон
// правился в исходнике и выкладывался на боевой сервер, причём данные там
// лежали дважды — таблицей для десктопа и карточками для мобильных.
// Здесь редактируются данные, вёрстку обеих версий собирает сайт.
//
// Авторизации пока нет — как и на странице возврата билета (этап users).
import { ref, computed, onMounted } from 'vue'

const SITE_API = '/site-api/v1/admin/contacts'

// Адрес сайта — для ссылки «посмотреть страницу» (VITE_SITE_URL в compose).
const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const KINDS = [
  { value: 'text', label: 'Текст' },
  { value: 'phone', label: 'Телефон' },
  { value: 'email', label: 'E-mail' },
  { value: 'fax', label: 'Факс' },
]

const details = ref([])
const points = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
// Снимок загруженных данных: по нему видно, есть ли несохранённые правки.
const snapshot = ref('')

const dirty = computed(() => snapshot.value !== JSON.stringify(payload()))

function payload() {
  return {
    details: details.value.map((d) => ({
      label: d.label,
      kind: d.kind,
      items: lines(d.items_text),
      is_active: d.is_active,
    })),
    points: points.value.map((p) => ({
      name: p.name,
      work_hours: lines(p.work_hours_text),
      phones: lines(p.phones_text),
      address: p.address,
      breaks: lines(p.breaks_text),
      is_active: p.is_active,
    })),
  }
}

/** Многострочное поле → список значений (пустые строки отбрасываются). */
function lines(text) {
  return String(text || '')
    .split('\n')
    .map((s) => s.trim())
    .filter((s) => s !== '')
}

// Собственный ключ строки: при перестановке строк ключ по индексу заставил бы
// Vue переиспользовать поля ввода и «переносить» в них фокус и выделение.
let seq = 0

function fill(data) {
  details.value = (data.details || []).map((d) => ({
    uid: ++seq,
    label: d.label,
    kind: d.kind,
    items_text: (d.items || []).join('\n'),
    is_active: d.is_active,
  }))
  points.value = (data.points || []).map((p) => ({
    uid: ++seq,
    name: p.name,
    work_hours_text: (p.work_hours || []).join('\n'),
    phones_text: (p.phones || []).join('\n'),
    address: p.address || '',
    breaks_text: (p.breaks || []).join('\n'),
    is_active: p.is_active,
  }))
  snapshot.value = JSON.stringify(payload())
}

async function req(method, body) {
  const r = await fetch(SITE_API, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  })
  let data = null
  try { data = await r.json() } catch (e) { /* не JSON */ }
  if (!r.ok || !data || data.success === false) {
    // Laravel validation: {message, errors: {поле: [тексты]}}
    const msg = data && data.errors
      ? Object.values(data.errors).flat().join(' ')
      : (data && (data.message || data.error)) || `Ошибка запроса (HTTP ${r.status})`
    throw new Error(msg)
  }
  return data.data
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    fill(await req('GET'))
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
    fill(await req('PUT', payload()))
    saved.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

function addDetail() {
  details.value.push({ uid: ++seq, label: '', kind: 'text', items_text: '', is_active: true })
}

function addPoint() {
  points.value.push({
    uid: ++seq,
    name: '', work_hours_text: '', phones_text: '', address: '', breaks_text: '', is_active: true,
  })
}

// Списки адресуем по имени, а не ссылкой на ref: в шаблоне Vue разворачивает
// ref, и в функцию пришёл бы массив без .value.
function listByName(name) {
  return name === 'details' ? details : points
}

function remove(name, index) {
  listByName(name).value.splice(index, 1)
}

function move(name, index, delta) {
  const list = listByName(name)
  const to = index + delta
  if (to < 0 || to >= list.value.length) return
  const [row] = list.value.splice(index, 1)
  list.value.splice(to, 0, row)
}

// Порядок строк перетаскиванием за ручку ⠿ (для этого и нужен HTML5 drag).
const dragging = ref(null) // { name: 'details'|'points', index }

function onDragStart(name, index, event) {
  dragging.value = { name, index }
  event.dataTransfer.effectAllowed = 'move'
  // Firefox не начинает перетаскивание без данных в буфере.
  event.dataTransfer.setData('text/plain', String(index))
}

function onDragOver(name, event) {
  if (dragging.value && dragging.value.name === name) event.preventDefault()
}

function onDrop(name, index) {
  if (!dragging.value || dragging.value.name !== name) return
  const list = listByName(name)
  const from = dragging.value.index
  dragging.value = null
  if (from === index) return
  const [row] = list.value.splice(from, 1)
  list.value.splice(index, 0, row)
}

onMounted(() => {
  document.title = 'Контакты сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Контакты сайта</h1>
    <p class="muted">
      Данные страницы <a :href="`${SITE_URL}/contacts`" target="_blank" rel="noopener">«Контакты» на сайте</a>.
      Скрытые строки остаются здесь, но на сайт не выводятся.
    </p>

    <div v-if="loading" class="muted loading">Загрузка…</div>
    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty" class="alert ok">Изменения сохранены и уже видны на сайте.</div>

    <template v-if="!loading">
      <!-- Реквизиты организации (верхняя таблица страницы) -->
      <section class="ct-block">
        <h3>Реквизиты организации</h3>
        <p class="muted ct-hint">
          Каждое значение — с новой строки: у справочной так выводятся два телефона.
        </p>

        <div
          v-for="(d, i) in details"
          :key="d.uid"
          class="ct-row"
          :class="{ 'ct-hidden': !d.is_active }"
          @dragover="onDragOver('details', $event)"
          @drop="onDrop('details', i)"
        >
          <div class="ct-handle" draggable="true" title="Перетащите, чтобы изменить порядок"
               @dragstart="onDragStart('details', i, $event)">⠿</div>

          <div class="ct-fields">
            <label class="ct-field ct-grow">
              <span class="ct-label">Название</span>
              <input v-model="d.label" type="text" placeholder="Например, Справочная Автовокзала" />
            </label>

            <label class="ct-field">
              <span class="ct-label">Тип значения</span>
              <select v-model="d.kind">
                <option v-for="k in KINDS" :key="k.value" :value="k.value">{{ k.label }}</option>
              </select>
            </label>

            <label class="ct-field ct-grow">
              <span class="ct-label">Значения</span>
              <textarea v-model="d.items_text" rows="2" placeholder="+7 (4862) 72-11-11"></textarea>
            </label>
          </div>

          <div class="ct-actions">
            <label class="ct-check" title="Показывать на сайте">
              <input v-model="d.is_active" type="checkbox" /> на сайте
            </label>
            <button class="btn small" :disabled="i === 0" @click="move('details', i, -1)">↑</button>
            <button class="btn small" :disabled="i === details.length - 1" @click="move('details', i, 1)">↓</button>
            <button class="btn small danger" @click="remove('details', i)">✕</button>
          </div>
        </div>

        <button class="btn" @click="addDetail">+ Добавить строку</button>
      </section>

      <!-- Остановочные пункты (нижняя таблица страницы) -->
      <section class="ct-block">
        <h3>Остановочные пункты</h3>
        <p class="muted ct-hint">
          Время работы, телефоны и перерывы — по одному значению в строке.
          Нумерация на сайте проставляется автоматически по видимым пунктам.
        </p>

        <div
          v-for="(p, i) in points"
          :key="p.uid"
          class="ct-row"
          :class="{ 'ct-hidden': !p.is_active }"
          @dragover="onDragOver('points', $event)"
          @drop="onDrop('points', i)"
        >
          <div class="ct-handle" draggable="true" title="Перетащите, чтобы изменить порядок"
               @dragstart="onDragStart('points', i, $event)">⠿</div>

          <div class="ct-fields">
            <label class="ct-field ct-grow">
              <span class="ct-label">Пункт</span>
              <input v-model="p.name" type="text" placeholder="Кассовый пункт «Болхов»" />
            </label>

            <label class="ct-field">
              <span class="ct-label">Время работы</span>
              <textarea v-model="p.work_hours_text" rows="2" placeholder="пн-сб 6:15 – 17:15"></textarea>
            </label>

            <label class="ct-field">
              <span class="ct-label">Телефоны</span>
              <textarea v-model="p.phones_text" rows="2" placeholder="+7 (980) 361-49-57"></textarea>
            </label>

            <label class="ct-field ct-grow">
              <span class="ct-label">Адрес</span>
              <input v-model="p.address" type="text" placeholder="Орловская обл., г. Болхов, ул. Первомайская, д. 22" />
            </label>

            <label class="ct-field">
              <span class="ct-label">Перерыв</span>
              <textarea v-model="p.breaks_text" rows="2" placeholder="9:25 – 10:00"></textarea>
            </label>
          </div>

          <div class="ct-actions">
            <label class="ct-check" title="Показывать на сайте">
              <input v-model="p.is_active" type="checkbox" /> на сайте
            </label>
            <button class="btn small" :disabled="i === 0" @click="move('points', i, -1)">↑</button>
            <button class="btn small" :disabled="i === points.length - 1" @click="move('points', i, 1)">↓</button>
            <button class="btn small danger" @click="remove('points', i)">✕</button>
          </div>
        </div>

        <button class="btn" @click="addPoint">+ Добавить пункт</button>
      </section>

      <div class="ct-save">
        <button class="btn primary" :disabled="saving || !dirty" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
        <button class="btn" :disabled="saving || !dirty" @click="load">Отменить изменения</button>
        <span v-if="dirty" class="muted">Есть несохранённые изменения</span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ct-block { margin: 14px 0; padding: 12px 16px; background: #fff; border: 1px solid var(--line); border-radius: 8px; }
.ct-block h3 { margin: 0 0 4px; }
.ct-hint { margin: 0 0 12px; font-size: 12px; }

.ct-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-top: 1px solid var(--line); }
.ct-row:first-of-type { border-top: none; }
.ct-hidden { opacity: .55; }

.ct-handle { cursor: grab; color: var(--muted); font-size: 18px; line-height: 28px; user-select: none; }
.ct-handle:active { cursor: grabbing; }

.ct-fields { display: flex; flex-wrap: wrap; gap: 10px; flex: 1 1 auto; min-width: 0; }
.ct-field { display: flex; flex-direction: column; gap: 4px; min-width: 160px; }
.ct-grow { flex: 1 1 220px; }
.ct-label { font-size: 12px; color: var(--muted); }
.ct-field input, .ct-field select, .ct-field textarea {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; width: 100%;
}
.ct-field textarea { resize: vertical; }

.ct-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.ct-check { font-size: 12px; color: var(--muted); white-space: nowrap; }
.btn.danger { color: #b00; }

.ct-save { display: flex; align-items: center; gap: 10px; margin: 16px 0 40px; }
.alert.ok { background: #eefbee; border-color: #a8d6a8; }
</style>
