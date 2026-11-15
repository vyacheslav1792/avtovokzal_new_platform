<script setup>
/**
 * Блоки сайта («виджеты»): редактируемые куски HTML в заранее отведённых
 * местах страниц — баннер в блоке поиска, приветствие, памятки пассажирам,
 * объявления над расписанием и списком рейсов.
 *
 * В legacy это были PHP-файлы avtovokzal57/vidjets/*.php плюс куски,
 * вбитые прямо в url/index.php: приветствие и обращение правились выкладкой
 * кода на боевой сервер. Форк админки давал редактировать те же файлы как
 * исходный код; здесь у каждого места есть название и описание, а сам текст
 * правится так же, как страница сайта.
 *
 * Список мест задаёт сервер (config/site_blocks.php): завести блок, которому
 * негде появиться, нельзя.
 */
import { defineAsyncComponent, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import FileManager from '../components/FileManager.vue'
import HtmlEditor from '../components/HtmlEditor.vue'
import { fetchBlocks, saveBlocks } from '../siteApi'
import { can } from '../auth'

const CodeEditor = defineAsyncComponent(() => import('../components/CodeEditor.vue'))

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const blocks = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const snapshot = ref('')
const filesOpen = ref(false)
// Раскрытые блоки и режим правки каждого: редактор создаётся только для
// раскрытого — восемь iframe сразу ни к чему.
const open = reactive({})
const modes = reactive({})

function dirty() {
  return snapshot.value !== JSON.stringify(blocks.value)
}

function localInput(value) {
  if (!value) return ''

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const pad = (n) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    blocks.value = (await fetchBlocks()).map((block) => ({
      ...block,
      publish_from: localInput(block.publish_from),
      publish_to: localInput(block.publish_to),
    }))
    snapshot.value = JSON.stringify(blocks.value)
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
    const payload = blocks.value.map((block) => ({
      key: block.key,
      content: block.content || '',
      is_active: block.is_active,
      publish_from: block.publish_from || null,
      publish_to: block.publish_to || null,
    }))

    blocks.value = (await saveBlocks(payload)).map((block) => ({
      ...block,
      publish_from: localInput(block.publish_from),
      publish_to: localInput(block.publish_to),
    }))
    snapshot.value = JSON.stringify(blocks.value)
    saved.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

/**
 * Файл из менеджера: вставить его в нужный из восьми редакторов нечем —
 * копируем адрес, дальше его вставляют ссылкой или картинкой. Картинки для
 * этого не нужны: у самого редактора есть кнопка «Картинка».
 */
function copyFileUrl(file) {
  filesOpen.value = false
  navigator.clipboard?.writeText(file.url)
  window.alert(`Адрес файла скопирован: ${file.url}`)
}

function toggle(key) {
  open[key] = !open[key]
  if (modes[key] === undefined) modes[key] = 'visual'
}

/**
 * Короткая выжимка содержимого — чтобы в свёрнутом виде было видно, что
 * внутри. Текста может не быть вовсе (баннер — это одна картинка), и тогда
 * писать «блок пуст» нельзя: он не пуст, просто без слов.
 */
function excerpt(block) {
  const html = String(block.content || '')

  if (!html.trim()) return 'Блок пуст — на сайте его нет.'

  const text = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

  if (!text) return 'Картинка без подписи — откройте, чтобы посмотреть.'

  return text.length > 160 ? `${text.slice(0, 160)}…` : text
}

onBeforeRouteLeave(() => {
  if (!dirty()) return true

  return window.confirm('Изменения не сохранены. Уйти со страницы?')
})

onMounted(() => {
  document.title = 'Блоки сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Блоки сайта</h1>
    <p class="muted">
      Объявления и баннеры в отведённых местах сайта. Место выбрать нельзя — оно задано
      в самом сайте; можно написать, что в нём показывать, и до какого момента.
      Срочные новости лучше публиковать в разделе
      <router-link to="/news">«Новости»</router-link>: у них есть своя страница и дата.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty()" class="alert ok">Изменения сохранены и уже видны на сайте.</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <div v-for="block in blocks" :key="block.key" class="panel block-panel">
        <div class="block-head">
          <div>
            <h2>{{ block.title }}</h2>
            <p class="hint block-hint">{{ block.hint }}</p>
          </div>
          <div class="block-state">
            <span :class="block.visible ? 'badge-on' : 'badge-off'">
              {{ block.visible ? 'на сайте' : (block.content ? 'не показывается' : 'пусто') }}
            </span>
            <a v-if="block.page" class="btn small" :href="`${SITE_URL}${block.page}`" target="_blank" rel="noopener">
              Посмотреть
            </a>
            <button type="button" class="btn small" @click="toggle(block.key)">
              {{ open[block.key] ? 'Свернуть' : 'Править' }}
            </button>
          </div>
        </div>

        <p v-if="!open[block.key]" class="block-excerpt muted">
          {{ excerpt(block) }}
        </p>

        <template v-else>
          <div class="filters-grid block-opts">
            <label class="check"><input v-model="block.is_active" type="checkbox"> показывать на сайте</label>
            <label class="field">
              <span class="field-label">Показывать с (необязательно)</span>
              <input v-model="block.publish_from" type="datetime-local">
            </label>
            <label class="field">
              <span class="field-label">Показывать до (необязательно)</span>
              <input v-model="block.publish_to" type="datetime-local">
            </label>
          </div>

          <div class="editor-head">
            <div class="tabs">
              <button
                type="button"
                class="tab"
                :class="{ active: modes[block.key] !== 'html' }"
                @click="modes[block.key] = 'visual'"
              >Визуально</button>
              <button
                type="button"
                class="tab"
                :class="{ active: modes[block.key] === 'html' }"
                @click="modes[block.key] = 'html'"
              >HTML</button>
            </div>
          </div>

          <HtmlEditor
            v-if="modes[block.key] !== 'html'"
            v-model="block.content"
            height="260px"
            @pick-file="filesOpen = true"
          />
          <CodeEditor v-else v-model="block.content" />

          <p class="hint" style="margin-top: 8px">
            Пустой блок на сайте не выводится — так его и убирают.
          </p>
        </template>
      </div>

      <p class="edit-actions">
        <button v-if="can('blocks.edit')" type="button" class="btn primary" :disabled="saving || !dirty()" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
        <span v-if="dirty()" class="muted">есть несохранённые изменения</span>
      </p>
    </template>

    <!-- Блоку нужны те же файлы сайта, что и странице: PDF и картинки -->
    <FileManager v-if="filesOpen" @close="filesOpen = false" @pick="copyFileUrl" />
  </div>
</template>
