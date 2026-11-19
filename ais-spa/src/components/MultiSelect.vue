<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

/*
 * Выбор НЕСКОЛЬКИХ значений с поиском. Нужен там, где список длинный, а
 * считают обычно по горстке: хозяйств два десятка, АТП бывает под сотню.
 * Обычный <select multiple> для этого не годится — в нём нет поиска, а
 * снять выбор с одного пункта мышью, не сбив остальные, умеет не каждый.
 *
 * Пустой выбор = ВСЕ: так же было в legacy пунктом «Все», и отдельный
 * пункт с тем же смыслом рядом с галочками только путал бы.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, required: true },   // [{value, label}]
  allLabel: { type: String, default: 'Все' },
  // Чем подписать, когда выбранных больше, чем влезает в кнопку.
  itemsLabel: { type: String, default: 'выбрано' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const query = ref('')
const root = ref(null)
const search = ref(null)

/*
 * Выбранное держим СВОЕЙ копией, а не читаем props на каждый клик.
 * `emit` доходит до props только после перерисовки родителя, поэтому два
 * быстрых клика подряд оба считали бы прежний состав — и второй затирал
 * бы первый (ловилось при проверке: отмечали два АТП, оставалось одно).
 */
const picked = ref([...props.modelValue])

watch(() => props.modelValue, (value) => {
  if (value.join('|') !== picked.value.join('|')) picked.value = [...value]
})

// Значения приходят и числами (коды АТП), и строками — сравниваем как строки.
const selectedKeys = computed(() => new Set(picked.value.map((v) => String(v))))

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options

  return props.options.filter((o) => String(o.label).toLowerCase().includes(q))
})

const title = computed(() => {
  if (!picked.value.length) return props.allLabel
  const labels = picked.value
    .map((v) => props.options.find((o) => String(o.value) === String(v))?.label ?? String(v))
  if (labels.length <= 2) return labels.join(', ')

  return `${props.itemsLabel}: ${labels.length}`
})

function apply(next) {
  picked.value = next
  emit('update:modelValue', next)
}

function toggle(value) {
  const key = String(value)
  const next = picked.value.filter((v) => String(v) !== key)
  if (next.length === picked.value.length) next.push(value)
  apply(next)
}

/** «Все» — это пустой выбор, отдельного значения для него нет. */
function selectAll() {
  apply([])
}

/** Выбрать всё, что сейчас показано поиском, — по одному кликать долго. */
function selectFiltered() {
  const next = [...picked.value]
  for (const o of filtered.value) {
    if (!selectedKeys.value.has(String(o.value))) next.push(o.value)
  }
  apply(next)
}

async function openPanel() {
  if (props.disabled) return
  open.value = !open.value
  if (!open.value) return
  query.value = ''
  await nextTick()
  search.value?.focus()
}

function onDocumentPointerDown(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}
function onKeydown(e) {
  if (e.key === 'Escape') open.value = false
}

watch(open, (yes) => {
  if (yes) {
    document.addEventListener('pointerdown', onDocumentPointerDown)
    document.addEventListener('keydown', onKeydown)
  } else {
    document.removeEventListener('pointerdown', onDocumentPointerDown)
    document.removeEventListener('keydown', onKeydown)
  }
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="root" class="ms">
    <button
      type="button" class="ms-button" :class="{ open }"
      :disabled="disabled" :title="title" @click="openPanel"
    >
      <span class="ms-title" :class="{ 'ms-all': !picked.length }">{{ title }}</span>
      <span class="ms-caret">▾</span>
    </button>

    <div v-if="open" class="ms-panel">
      <input
        ref="search" v-model="query" type="search" class="ms-search"
        placeholder="Поиск…" @keydown.enter.prevent
      />

      <div class="ms-tools">
        <button type="button" class="btn small" :disabled="!picked.length" @click="selectAll">
          {{ allLabel }}
        </button>
        <button
          type="button" class="btn small"
          :disabled="!filtered.length" @click="selectFiltered"
        >Отметить все{{ query.trim() ? ' найденные' : '' }}</button>
        <span class="ms-count muted">{{ picked.length }} из {{ options.length }}</span>
      </div>

      <div class="ms-list">
        <label v-for="o in filtered" :key="o.value" class="ms-item">
          <input
            type="checkbox" :checked="selectedKeys.has(String(o.value))"
            @change="toggle(o.value)"
          />
          <span>{{ o.label }}</span>
        </label>
        <div v-if="!filtered.length" class="ms-empty muted">
          {{ loading ? 'Загрузка…' : 'Ничего не найдено' }}
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ms { position: relative; }

.ms-button {
  display: flex; align-items: center; gap: 8px; width: 100%; min-width: 150px;
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  background: #fff; font: inherit; font-size: 14px; cursor: pointer; text-align: left;
}
.ms-button:disabled { opacity: .55; cursor: default; }
.ms-button.open { border-color: var(--accent); }
/* Название не обрезаем многоточием: по нему и различают хозяйства и АТП. */
.ms-title { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
.ms-all { color: var(--muted); }
.ms-caret { color: var(--muted); font-size: 11px; }

.ms-panel {
  position: absolute; z-index: 20; top: calc(100% + 4px); left: 0;
  min-width: 100%; max-width: 420px; padding: 8px;
  background: var(--panel); border: 1px solid var(--line-strong); border-radius: 10px;
  box-shadow: 0 8px 24px rgba(31, 39, 51, .14);
}
.ms-search {
  width: 100%; padding: 6px 8px; border: 1px solid var(--line-strong);
  border-radius: 7px; font: inherit; font-size: 14px; background: #fff;
}
.ms-tools { display: flex; align-items: center; gap: 6px; margin: 8px 0 6px; }
.ms-count { margin-left: auto; font-size: 12px; white-space: nowrap; }

.ms-list { max-height: 260px; overflow: auto; }
.ms-item {
  display: flex; align-items: flex-start; gap: 7px;
  padding: 4px 4px; border-radius: 6px; cursor: pointer; font-size: 14px;
}
.ms-item:hover { background: var(--head); }
.ms-item input { margin-top: 2px; }
.ms-empty { padding: 8px 4px; font-size: 13px; }
</style>
