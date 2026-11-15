<script setup>
/**
 * Редактор новости: заголовок, адрес, обложка, краткое содержание и полный
 * текст — визуально или в HTML с подсветкой. Плюс срок показа: по его
 * окончании новость уходит с сайта сама.
 *
 * В legacy это была правка avtovokzal57/vidjets/news.php с условием
 * time() < strtotime('2026-07-06') и выкладка файла на боевой сервер.
 */
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import FileManager from '../components/FileManager.vue'
import HtmlEditor from '../components/HtmlEditor.vue'
import ImageDialog from '../components/ImageDialog.vue'
import { createNews, fetchNewsItem, updateNews } from '../siteApi'
import { can } from '../auth'
import { slugify } from '../slug'

// CodeMirror весит больше всего остального приложения вместе взятого —
// грузим его только когда открыли HTML-режим.
const CodeEditor = defineAsyncComponent(() => import('../components/CodeEditor.vue'))

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const route = useRoute()
const router = useRouter()

const isNew = computed(() => route.params.id === 'new')

const news = ref(blank())

const mode = ref('visual')          // visual | html
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const filesOpen = ref(false)
const coverOpen = ref(false)
const visualEditor = ref(null)
// Адрес не трогали руками — тогда он следует за заголовком.
const slugTouched = ref(false)
const snapshot = ref('')

const dirty = computed(() => snapshot.value !== JSON.stringify(news.value))

function blank() {
  return {
    slug: '',
    title: '',
    meta_title: '',
    meta_description: '',
    lead: '',
    content: '',
    cover_url: '',
    cover_alt: '',
    // Дата новости по умолчанию — сегодня: объявление публикуют сразу.
    published_at: localInput(new Date()),
    publish_from: '',
    publish_to: '',
    is_active: true,
    on_index: true,
  }
}

/** Значение для <input type="datetime-local">: без секунд и без зоны. */
function localInput(value) {
  if (!value) return ''

  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const pad = (n) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function remember() {
  snapshot.value = JSON.stringify(news.value)
}

async function load() {
  if (isNew.value) {
    news.value = blank()
    slugTouched.value = false
    remember()

    return
  }

  loading.value = true
  error.value = ''
  try {
    const data = await fetchNewsItem(route.params.id)
    news.value = {
      slug: data.slug,
      title: data.title,
      meta_title: data.meta_title || '',
      meta_description: data.meta_description || '',
      lead: data.lead || '',
      content: data.content || '',
      cover_url: data.cover_url || '',
      cover_alt: data.cover_alt || '',
      published_at: localInput(data.published_at),
      publish_from: localInput(data.publish_from),
      publish_to: localInput(data.publish_to),
      is_active: data.is_active,
      on_index: data.on_index,
    }
    slugTouched.value = true
    document.title = `${data.title} · АИС`
    remember()
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
    const payload = {
      ...news.value,
      meta_title: news.value.meta_title || null,
      meta_description: news.value.meta_description || null,
      lead: news.value.lead || null,
      cover_url: news.value.cover_url || null,
      cover_alt: news.value.cover_alt || null,
      publish_from: news.value.publish_from || null,
      publish_to: news.value.publish_to || null,
    }

    const data = isNew.value
      ? await createNews(payload)
      : await updateNews(route.params.id, payload)

    saved.value = true
    remember()

    if (isNew.value) await router.replace(`/news/${data.id}`)
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

/** Файл из менеджера: в визуальном режиме вставляем, иначе копируем адрес. */
function pickFile(file) {
  filesOpen.value = false

  if (mode.value === 'visual' && visualEditor.value) {
    visualEditor.value.insertFile(file)

    return
  }

  navigator.clipboard?.writeText(file.url)
  window.alert(`Адрес файла скопирован: ${file.url}`)
}

/**
 * Обложку выбираем тем же диалогом, что и картинки в тексте: загрузка с
 * компьютера, галерея с превью и адрес — всё уже там. Размер и обтекание
 * обложке не нужны (её выводит сам сайт), поэтому берём только адрес
 * и подпись.
 */
function pickCover(image) {
  news.value.cover_url = image.src
  if (image.alt) news.value.cover_alt = image.alt
  coverOpen.value = false
}

// Адрес следует за заголовком, пока его не правили руками: у новости он
// служебный, и заставлять придумывать его отдельно незачем.
watch(() => news.value.title, (title) => {
  if (!slugTouched.value) news.value.slug = slugify(title, 120)
})

onBeforeRouteLeave(() => {
  if (!dirty.value) return true

  return window.confirm('Изменения не сохранены. Уйти со страницы?')
})

watch(() => route.params.id, load)

onMounted(() => {
  document.title = isNew.value ? 'Новая новость · АИС' : 'Новость · АИС'
  load()

  window.addEventListener('beforeunload', (e) => {
    if (!dirty.value) return
    e.preventDefault()
    e.returnValue = ''
  })
})
</script>

<template>
  <div class="page-edit">
    <h1>{{ isNew ? 'Новая новость' : news.title || 'Новость' }}</h1>
    <p class="muted">
      <router-link to="/news">← Все новости</router-link>
      <template v-if="!isNew && news.slug">
        ·
        <a :href="`${SITE_URL}/news/${news.slug}`" target="_blank" rel="noopener">Открыть на сайте</a>
      </template>
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty" class="alert ok">Изменения сохранены и уже видны на сайте.</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <div class="filters">
        <div class="filters-grid">
          <label class="field">
            <span class="field-label">Заголовок новости</span>
            <input v-model="news.title" type="text" style="min-width: 420px">
          </label>
          <label class="field">
            <span class="field-label">Адрес на сайте</span>
            <input
              v-model="news.slug"
              type="text"
              placeholder="den-polya"
              style="min-width: 220px"
              @input="slugTouched = true"
            >
          </label>
        </div>

        <div class="filters-grid" style="margin-top: 12px">
          <label class="field">
            <span class="field-label">Дата новости (видна посетителю)</span>
            <input v-model="news.published_at" type="datetime-local">
          </label>
          <label class="field">
            <span class="field-label">Показывать с (необязательно)</span>
            <input v-model="news.publish_from" type="datetime-local">
          </label>
          <label class="field">
            <span class="field-label">Показывать до (необязательно)</span>
            <input v-model="news.publish_to" type="datetime-local">
          </label>
          <label class="field">
            <span class="field-label">Состояние</span>
            <span class="news-checks">
              <label class="check"><input v-model="news.is_active" type="checkbox"> опубликована</label>
              <label class="check"><input v-model="news.on_index" type="checkbox"> на главной</label>
            </span>
          </label>
        </div>

        <p class="hint" style="margin-top: 10px">
          Срок показа можно не задавать — тогда новость висит, пока её не выключат.
          «Показывать до» снимает объявление с сайта в указанный момент само:
          в legacy для этого правили условие в исходнике.
        </p>
      </div>

      <div class="filters">
        <div class="filters-grid news-cover-row">
          <div class="news-cover">
            <img v-if="news.cover_url" :src="`${SITE_URL}${news.cover_url}`" alt="Обложка новости">
            <span v-else class="muted">Обложки нет</span>
          </div>
          <div class="field news-cover-fields">
            <span class="field-label">Обложка (видна в списке новостей и при пересылке ссылки)</span>
            <div class="row-actions">
              <button type="button" class="btn small primary" @click="coverOpen = true">
                {{ news.cover_url ? 'Заменить обложку' : 'Выбрать обложку' }}
              </button>
              <button v-if="news.cover_url" type="button" class="btn small" @click="news.cover_url = ''">
                Убрать
              </button>
            </div>
            <label class="field" style="margin-top: 10px">
              <span class="field-label">Подпись обложки для незрячих (alt)</span>
              <input v-model="news.cover_alt" type="text" placeholder="Что на картинке">
            </label>
          </div>
        </div>
      </div>

      <h2 class="news-section">Краткое содержание</h2>
      <p class="hint">
        Показывается в списке новостей, на главной и при пересылке ссылки в мессенджере.
        Если пусто — берётся начало полного текста.
      </p>
      <HtmlEditor v-model="news.lead" height="180px" @pick-file="filesOpen = true" />

      <h2 class="news-section">Полный текст</h2>
      <div class="editor-head">
        <div class="tabs">
          <button type="button" class="tab" :class="{ active: mode === 'visual' }" @click="mode = 'visual'">
            Визуально
          </button>
          <button type="button" class="tab" :class="{ active: mode === 'html' }" @click="mode = 'html'">
            HTML
          </button>
        </div>
        <button type="button" class="btn small" @click="filesOpen = true">Файлы сайта</button>
      </div>

      <HtmlEditor
        v-show="mode === 'visual'"
        ref="visualEditor"
        v-model="news.content"
        @pick-file="filesOpen = true"
      />
      <CodeEditor v-if="mode === 'html'" v-model="news.content" />

      <h2 class="news-section">Для поисковиков</h2>
      <div class="filters">
        <div class="filters-grid">
          <label class="field">
            <span class="field-label">Заголовок вкладки (title)</span>
            <input v-model="news.meta_title" type="text" :placeholder="news.title" style="min-width: 480px">
          </label>
          <label class="field">
            <span class="field-label">Описание (description)</span>
            <input v-model="news.meta_description" type="text" style="min-width: 480px">
          </label>
        </div>
        <p class="hint" style="margin-top: 10px">
          Можно не заполнять: вместо заголовка вкладки сайт возьмёт заголовок новости,
          вместо описания — краткое содержание.
        </p>
      </div>

      <p class="edit-actions">
        <button v-if="can('news.edit')" type="button" class="btn primary" :disabled="saving || !dirty" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
        <span v-if="dirty" class="muted">есть несохранённые изменения</span>
      </p>
    </template>

    <FileManager v-if="filesOpen" @close="filesOpen = false" @pick="pickFile" />
    <ImageDialog v-if="coverOpen" @close="coverOpen = false" @apply="pickCover" />
  </div>
</template>
