<script setup>
// Антиспам-словарь формы обратной связи. В legacy это была цепочка из
// ~60 preg_match прямо в теле avtovokzal57/url/contact.php: чтобы добавить
// спам-слово, правили исходник и выкладывали его на боевой сервер.
// Здесь правила лежат в базе и правятся отсюда.
//
// Авторизации пока нет — как и на остальных админских страницах (этап users).
import { ref, computed, onMounted } from 'vue'
import { fetchSpamRules, saveSpamRules } from '../siteApi'

const KINDS = [
  { value: 'substring', label: 'Слово или часть слова' },
  { value: 'regex', label: 'Регулярное выражение' },
  { value: 'latin_dominates', label: 'Латиницы больше, чем кириллицы' },
]

const SCOPES = [
  { value: 'body', label: 'Текст сообщения' },
  { value: 'name', label: 'Имя' },
  { value: 'email', label: 'E-mail' },
  { value: 'any', label: 'Везде' },
]

const rules = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const snapshot = ref('')

// Своя проверка текста — как оператор проверил бы правило на живом примере.
const probe = ref('')

const dirty = computed(() => snapshot.value !== JSON.stringify(payload()))

const activeCount = computed(() => rules.value.filter((r) => r.is_active).length)

// Собственный ключ строки: ключ по индексу заставил бы Vue переиспользовать
// поля ввода и «переносить» в них фокус при перестановке.
let seq = 0

function payload() {
  return rules.value.map((r) => ({
    kind: r.kind,
    scope: r.scope,
    pattern: r.kind === 'latin_dominates' ? null : r.pattern,
    note: r.note,
    is_active: r.is_active,
  }))
}

function fill(data) {
  rules.value = (data.rules || []).map((r) => ({
    uid: ++seq,
    kind: r.kind,
    scope: r.scope,
    pattern: r.pattern || '',
    note: r.note || '',
    is_active: r.is_active,
  }))
  snapshot.value = JSON.stringify(payload())
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    fill(await fetchSpamRules())
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
    fill(await saveSpamRules(payload()))
    saved.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

function add() {
  rules.value.push({ uid: ++seq, kind: 'substring', scope: 'body', pattern: '', note: '', is_active: true })
}

function remove(index) {
  rules.value.splice(index, 1)
}

function move(index, delta) {
  const to = index + delta
  if (to < 0 || to >= rules.value.length) return
  const [row] = rules.value.splice(index, 1)
  rules.value.splice(to, 0, row)
}

/**
 * Проверка текста по несохранённому списку — прямо в браузере, теми же
 * правилами, что применяет backend (App\Services\SpamFilter). Если правило
 * сработает, сообщение уйдёт в «Спам», но не пропадёт: оно всё равно
 * сохранится и будет видно в разделе «Обращения с сайта».
 */
const probeResult = computed(() => {
  const text = probe.value
  if (!text.trim()) return null

  for (const rule of rules.value) {
    if (!rule.is_active) continue
    // Проверяем как для текста сообщения: правила по имени и адресу здесь
    // не примеряем — для них своя область.
    if (rule.scope !== 'body' && rule.scope !== 'any') continue

    if (rule.kind === 'latin_dominates') {
      const cyrillic = (text.match(/[а-яё]/gi) || []).length
      const latin = (text.match(/[a-z]/gi) || []).length
      if (cyrillic <= latin) return rule
      continue
    }

    if (!rule.pattern) continue

    if (rule.kind === 'substring') {
      if (text.toLowerCase().includes(rule.pattern.toLowerCase())) return rule
      continue
    }

    try {
      if (new RegExp(rule.pattern, 'iu').test(text)) return rule
    } catch (e) {
      // Битое выражение здесь просто пропускаем — сохранить его не дадут.
    }
  }

  return null
})

function kindLabel(value) {
  return (KINDS.find((k) => k.value === value) || {}).label || value
}

onMounted(() => {
  document.title = 'Антиспам-словарь · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Антиспам-словарь</h1>
    <p class="muted">
      Правила для формы <router-link to="/feedback">«Напишите нам»</router-link>.
      Сработавшее правило не удаляет обращение — оно сохраняется со статусом
      «Спам» и остаётся видно оператору (в legacy такое сообщение исчезало
      бесследно, а письмо просто не уходило).
      Слишком общие слова словаря — «заказ», «телефон», «услуги» — ловят и
      обычные вопросы пассажиров: такие правила можно выключить галочкой.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty" class="alert ok">Словарь сохранён — правила действуют сразу.</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <section class="sr-block">
        <h3>Проверить текст</h3>
        <p class="muted sr-hint">
          Впишите сообщение и посмотрите, сработает ли на нём словарь.
          Проверка идёт по текущему списку, в том числе по несохранённым правкам.
        </p>
        <textarea v-model="probe" rows="3" class="sr-probe" placeholder="Например: Здравствуйте, во сколько уходит рейс до Болхова?"></textarea>
        <p v-if="probe.trim()" :class="probeResult ? 'sr-verdict bad' : 'sr-verdict good'">
          <template v-if="probeResult">
            Попадёт в спам: {{ probeResult.note || probeResult.pattern || kindLabel(probeResult.kind) }}
          </template>
          <template v-else>
            Проходит — обращение попадёт в работу.
          </template>
        </p>
      </section>

      <section class="sr-block">
        <div class="sr-head">
          <h3>Правила</h3>
          <span class="muted">действующих: {{ activeCount }} из {{ rules.length }}</span>
        </div>

        <div v-for="(r, i) in rules" :key="r.uid" class="sr-row" :class="{ 'sr-off': !r.is_active }">
          <div class="sr-num muted">{{ i + 1 }}</div>

          <div class="sr-fields">
            <label class="sr-field">
              <span class="sr-label">Тип</span>
              <select v-model="r.kind">
                <option v-for="k in KINDS" :key="k.value" :value="k.value">{{ k.label }}</option>
              </select>
            </label>

            <label class="sr-field">
              <span class="sr-label">Где проверять</span>
              <select v-model="r.scope">
                <option v-for="s in SCOPES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </label>

            <label class="sr-field sr-grow">
              <span class="sr-label">Образец</span>
              <input
                v-model="r.pattern"
                type="text"
                :disabled="r.kind === 'latin_dominates'"
                :placeholder="r.kind === 'regex' ? 'флакон.*' : 'коммерческое предложение'"
              />
            </label>

            <label class="sr-field sr-grow">
              <span class="sr-label">Пояснение</span>
              <input v-model="r.note" type="text" placeholder="Зачем правило заведено" />
            </label>
          </div>

          <div class="sr-actions">
            <label class="sr-check" title="Правило действует">
              <input v-model="r.is_active" type="checkbox" /> вкл.
            </label>
            <button class="btn small" :disabled="i === 0" @click="move(i, -1)">↑</button>
            <button class="btn small" :disabled="i === rules.length - 1" @click="move(i, 1)">↓</button>
            <button class="btn small danger" @click="remove(i)">✕</button>
          </div>
        </div>

        <button class="btn" @click="add">+ Добавить правило</button>
      </section>

      <div class="sr-save">
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
.sr-block { margin: 14px 0; padding: 12px 16px; background: #fff; border: 1px solid var(--line); border-radius: 8px; }
.sr-block h3 { margin: 0 0 4px; }
.sr-hint { margin: 0 0 10px; font-size: 12px; }
.sr-head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 8px; }

.sr-probe {
  width: 100%; padding: 8px 10px; border: 1px solid var(--line-strong); border-radius: 8px;
  font: inherit; font-size: 14px; resize: vertical;
}
.sr-verdict { margin: 8px 0 0; font-size: 14px; }
.sr-verdict.good { color: #1a7f37; }
.sr-verdict.bad { color: #b00; }

.sr-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-top: 1px solid var(--line); }
.sr-row:first-of-type { border-top: none; }
.sr-off { opacity: .55; }
.sr-num { width: 24px; text-align: right; line-height: 30px; font-size: 12px; }

.sr-fields { display: flex; flex-wrap: wrap; gap: 10px; flex: 1 1 auto; min-width: 0; }
.sr-field { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
.sr-grow { flex: 1 1 200px; }
.sr-label { font-size: 12px; color: var(--muted); }
.sr-field input, .sr-field select {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; width: 100%;
}
.sr-field input:disabled { background: #f2f2f2; color: var(--muted); }

.sr-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.sr-check { font-size: 12px; color: var(--muted); white-space: nowrap; }
.btn.danger { color: #b00; }

.sr-save { display: flex; align-items: center; gap: 10px; margin: 16px 0 40px; }
.alert.ok { background: #eefbee; border-color: #a8d6a8; }
</style>
