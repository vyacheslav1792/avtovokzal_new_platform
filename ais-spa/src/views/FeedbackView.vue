<script setup>
// Обращения с сайта (форма «Напишите нам»). В legacy обращения нигде не
// хранились: письмо уходило на почту поддержки, и что с ним стало дальше —
// не видел никто. Здесь каждое обращение лежит в базе сайта, у него есть
// статус, заметка оператора и отметка времени ответа.
//
// Авторизации пока нет — как и на остальных админских страницах (этап users).
import { ref, computed, onMounted, watch } from 'vue'
import {
  fetchFeedback,
  fetchFeedbackMessage,
  updateFeedbackMessage,
  deleteFeedbackMessage,
} from '../siteApi'

const STATUSES = [
  { value: 'new', label: 'Новые' },
  { value: 'in_progress', label: 'В работе' },
  { value: 'answered', label: 'Отвечено' },
  { value: 'spam', label: 'Спам' },
]

const STATUS_LABELS = Object.fromEntries(STATUSES.map((s) => [s.value, s.label]))

// Подсветка строк — теми же цветами, что у отчётов АИС (row-* в style.css
// заданы только для table.dt, поэтому здесь свои классы).
const STATUS_CLASS = {
  new: 'st-new',
  in_progress: 'st-progress',
  answered: 'st-answered',
  spam: 'st-spam',
}

const filters = ref({ status: 'active', q: '', date_from: '', date_to: '' })
const page = ref(1)

const items = ref([])
const counts = ref({})
const total = ref(0)
const pages = ref(1)
const loading = ref(false)
const error = ref('')

// Открытое обращение (карточка справа).
const current = ref(null)
const saving = ref(false)
const note = ref('')

const hasFilters = computed(
  () => filters.value.q || filters.value.date_from || filters.value.date_to,
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchFeedback({
      status: filters.value.status,
      page: page.value,
      q: filters.value.q,
      date_from: filters.value.date_from,
      date_to: filters.value.date_to,
    })
    items.value = data.items
    counts.value = data.counts
    total.value = data.total
    pages.value = data.pages
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// Смена фильтра всегда возвращает на первую страницу: иначе после сужения
// выборки оператор видел бы пустой список на «пятой странице».
watch(() => [filters.value.status], () => { page.value = 1; load() })

async function open(row) {
  error.value = ''
  try {
    current.value = await fetchFeedbackMessage(row.id)
    note.value = current.value.admin_note || ''
  } catch (e) {
    error.value = e.message
  }
}

function close() {
  current.value = null
}

async function setStatus(status) {
  if (!current.value) return
  saving.value = true
  error.value = ''
  try {
    current.value = await updateFeedbackMessage(current.value.id, {
      status,
      admin_note: note.value,
    })
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function saveNote() {
  if (!current.value) return
  saving.value = true
  error.value = ''
  try {
    current.value = await updateFeedbackMessage(current.value.id, { admin_note: note.value })
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!current.value) return
  // Удаление необратимо — спрашиваем подтверждение.
  if (!window.confirm('Удалить обращение? Восстановить его будет нельзя.')) return
  saving.value = true
  try {
    await deleteFeedbackMessage(current.value.id)
    current.value = null
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

function go(delta) {
  const next = page.value + delta
  if (next < 1 || next > pages.value) return
  page.value = next
  load()
}

function applyFilters() {
  page.value = 1
  load()
}

function resetFilters() {
  filters.value = { status: filters.value.status, q: '', date_from: '', date_to: '' }
  applyFilters()
}

/** Короткая выжимка текста для списка. */
function preview(text) {
  const flat = String(text || '').replace(/\s+/g, ' ').trim()
  return flat.length > 120 ? `${flat.slice(0, 120)}…` : flat
}

onMounted(() => {
  document.title = 'Обращения с сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Обращения с сайта</h1>
    <p class="muted">
      Сообщения из формы «Напишите нам». Помеченные спамом писем не порождают,
      но остаются здесь: если словарь ошибся, обращение можно вернуть в работу.
      Словарь правится в разделе <router-link to="/spam-rules">«Антиспам-словарь»</router-link>.
    </p>

    <div class="fb-tabs">
      <button
        v-for="tab in [{ value: 'active', label: 'Актуальные' }, ...STATUSES, { value: 'all', label: 'Все' }]"
        :key="tab.value"
        class="fb-tab"
        :class="{ active: filters.status === tab.value }"
        @click="filters.status = tab.value"
      >
        {{ tab.label }}
        <span v-if="counts[tab.value] !== undefined" class="fb-count">{{ counts[tab.value] }}</span>
      </button>
    </div>

    <div class="fb-filters">
      <label class="fb-field fb-grow">
        <span class="fb-label">Поиск по имени, адресу, телефону и тексту</span>
        <input v-model="filters.q" type="search" placeholder="Например, Иванов" @keyup.enter="applyFilters" />
      </label>
      <label class="fb-field">
        <span class="fb-label">Дата с</span>
        <input v-model="filters.date_from" type="date" />
      </label>
      <label class="fb-field">
        <span class="fb-label">по</span>
        <input v-model="filters.date_to" type="date" />
      </label>
      <button class="btn primary" @click="applyFilters">Показать</button>
      <button v-if="hasFilters" class="btn" @click="resetFilters">Сбросить</button>
    </div>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <p v-if="!items.length" class="muted">Обращений нет.</p>

      <table v-else class="fb-table">
        <thead>
          <tr>
            <th>Дата</th>
            <th>Тема</th>
            <th>От кого</th>
            <th>Сообщение</th>
            <th>Статус</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in items"
            :key="row.id"
            class="fb-row"
            :class="[STATUS_CLASS[row.status], { current: current && current.id === row.id }]"
            @click="open(row)"
          >
            <td class="nowrap">{{ row.created_at }}</td>
            <td>{{ row.topic_title || '—' }}</td>
            <td>
              {{ row.name }}<br />
              <span class="muted">{{ row.email }}</span>
              <template v-if="row.phone"><br /><span class="muted">{{ row.phone }}</span></template>
            </td>
            <td class="fb-preview">{{ preview(row.body) }}</td>
            <td class="nowrap">
              {{ STATUS_LABELS[row.status] || row.status }}
              <div v-if="row.spam_reason" class="muted fb-reason">{{ row.spam_reason }}</div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="pages > 1" class="fb-pager">
        <button class="btn small" :disabled="page <= 1" @click="go(-1)">←</button>
        <span class="muted">Страница {{ page }} из {{ pages }} · всего {{ total }}</span>
        <button class="btn small" :disabled="page >= pages" @click="go(1)">→</button>
      </div>
    </template>

    <!-- Карточка обращения -->
    <div v-if="current" class="fb-card">
      <div class="fb-card-head">
        <h3>Обращение №{{ current.id }} от {{ current.created_at }}</h3>
        <button class="btn small" @click="close">✕</button>
      </div>

      <div class="fb-meta">
        <div><span class="muted">Тема:</span> {{ current.topic_title || '—' }}</div>
        <div><span class="muted">Имя:</span> {{ current.name }}</div>
        <div>
          <span class="muted">E-mail:</span>
          <a :href="`mailto:${current.email}?subject=Ответ на обращение с сайта автовокзал57.рф`">
            {{ current.email }}
          </a>
        </div>
        <div v-if="current.phone">
          <span class="muted">Телефон:</span>
          <a :href="`tel:${current.phone.replace(/[^\d+]/g, '')}`">{{ current.phone }}</a>
        </div>
        <div v-if="current.user_id"><span class="muted">Личный кабинет:</span> №{{ current.user_id }}</div>
        <div><span class="muted">IP:</span> {{ current.ip || '—' }}</div>
        <div>
          <span class="muted">Капча:</span>
          {{ current.captcha_passed === null ? 'не проверялась' : (current.captcha_passed ? 'пройдена' : 'не пройдена') }}
        </div>
        <div v-if="current.answered_at">
          <span class="muted">Отвечено:</span> {{ current.answered_at }}
        </div>
      </div>

      <div v-if="current.spam_reason" class="alert fb-spam">
        Помечено антиспамом: {{ current.spam_reason }}
      </div>

      <pre class="fb-body">{{ current.body }}</pre>

      <label class="fb-field fb-grow">
        <span class="fb-label">Заметка (видна только в АИС)</span>
        <textarea v-model="note" rows="3" placeholder="Например: перезвонили, вопрос решён"></textarea>
      </label>

      <div class="fb-actions">
        <button class="btn" :disabled="saving" @click="saveNote">Сохранить заметку</button>
        <span class="fb-sep"></span>
        <button
          v-for="s in STATUSES"
          :key="s.value"
          class="btn"
          :class="{ primary: current.status === s.value }"
          :disabled="saving"
          @click="setStatus(s.value)"
        >
          {{ s.label }}
        </button>
        <span class="fb-sep"></span>
        <button class="btn danger" :disabled="saving" @click="remove">Удалить</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fb-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 14px 0 10px; }
.fb-tab {
  padding: 6px 12px; border: 1px solid var(--line-strong); border-radius: 999px;
  background: #fff; cursor: pointer; font: inherit; font-size: 13px;
}
.fb-tab.active { background: var(--accent); color: #fff; border-color: transparent; }
.fb-count { margin-left: 6px; opacity: .7; font-size: 12px; }

.fb-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; margin-bottom: 14px; }
.fb-field { display: flex; flex-direction: column; gap: 4px; min-width: 140px; }
.fb-grow { flex: 1 1 260px; }
.fb-label { font-size: 12px; color: var(--muted); }
.fb-field input, .fb-field textarea {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; width: 100%;
}
.fb-field textarea { resize: vertical; }

.fb-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.fb-table th, .fb-table td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
.fb-row { cursor: pointer; }
.fb-row:hover td { background: #eaf1ff; }
.fb-row.current td { box-shadow: inset 0 0 0 1px var(--accent); }

/* Статус строки — теми же цветами, что подсветка отчётов АИС */
.fb-row.st-new td { background: #dcdcff; }
.fb-row.st-progress td { background: #fff7cf; }
.fb-row.st-answered td { background: #e9ffe9; }
.fb-row.st-spam td { background: #e8e8e8; color: #666; }
.fb-preview { max-width: 520px; }
.fb-reason { font-size: 12px; }
.nowrap { white-space: nowrap; }

.fb-pager { display: flex; align-items: center; gap: 10px; margin: 12px 0; }

.fb-card { margin: 18px 0 40px; padding: 14px 16px; background: #fff; border: 1px solid var(--line); border-radius: 8px; }
.fb-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.fb-card-head h3 { margin: 0; }
.fb-meta { display: flex; flex-wrap: wrap; gap: 6px 20px; margin: 10px 0; font-size: 14px; }
.fb-spam { margin: 10px 0; }
.fb-body {
  margin: 10px 0 14px; padding: 12px; background: #fafafa; border: 1px solid var(--line);
  border-radius: 8px; white-space: pre-wrap; word-break: break-word; font: inherit; font-size: 14px;
}
.fb-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; }
.fb-sep { width: 1px; height: 20px; background: var(--line-strong); }
.btn.danger { color: #b00; }
</style>
