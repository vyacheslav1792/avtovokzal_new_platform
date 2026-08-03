<script setup>
/**
 * Редактор страницы сайта: реквизиты (адрес, заголовок вкладки, описание)
 * и содержимое — визуально или в HTML с подсветкой. В legacy страница была
 * PHP-файлом avtovokzal57/url/<slug>.php, а <title>/description — веткой
 * switch в index.php; правка означала выкладку кода на боевой сервер.
 */
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import FileManager from '../components/FileManager.vue'
import HtmlEditor from '../components/HtmlEditor.vue'
import { createPage, fetchPage, updatePage } from '../siteApi'

// CodeMirror весит больше всего остального приложения вместе взятого —
// грузим его только когда открыли HTML-режим, а не при заходе в отчёты.
const CodeEditor = defineAsyncComponent(() => import('../components/CodeEditor.vue'))

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const route = useRoute()
const router = useRouter()

const isNew = computed(() => route.params.id === 'new')

const page = ref({
  slug: '',
  title: '',
  meta_title: '',
  meta_description: '',
  content: '',
  is_active: true,
})

const mode = ref('visual')          // visual | html
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const filesOpen = ref(false)
const visualEditor = ref(null)
// Снимок загруженных данных: по нему видно, есть ли несохранённые правки.
const snapshot = ref('')

const dirty = computed(() => snapshot.value !== JSON.stringify(page.value))

function remember() {
  snapshot.value = JSON.stringify(page.value)
}

async function load() {
  if (isNew.value) {
    remember()
    return
  }

  loading.value = true
  error.value = ''
  try {
    const data = await fetchPage(route.params.id)
    page.value = {
      slug: data.slug,
      title: data.title,
      meta_title: data.meta_title,
      meta_description: data.meta_description || '',
      content: data.content || '',
      is_active: data.is_active,
    }
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
    const payload = { ...page.value, meta_description: page.value.meta_description || null }
    const data = isNew.value
      ? await createPage(payload)
      : await updatePage(route.params.id, payload)

    saved.value = true
    remember()

    // Новая страница получила id — дальше правим её же, а не создаём копии.
    if (isNew.value) await router.replace(`/pages/${data.id}`)
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

/** Файл из менеджера: в визуальном режиме вставляем в текст, иначе — копируем адрес. */
function pickFile(file) {
  filesOpen.value = false

  if (mode.value === 'visual' && visualEditor.value) {
    visualEditor.value.insertFile(file)
    return
  }

  navigator.clipboard?.writeText(file.url)
  window.alert(`Адрес файла скопирован: ${file.url}`)
}

// Уходить с несохранёнными правками — почти всегда ошибка.
onBeforeRouteLeave(() => {
  if (!dirty.value) return true
  return window.confirm('Изменения не сохранены. Уйти со страницы?')
})

watch(() => route.params.id, load)

onMounted(() => {
  document.title = isNew.value ? 'Новая страница · АИС' : 'Страница сайта · АИС'
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
    <h1>{{ isNew ? 'Новая страница' : page.title || 'Страница сайта' }}</h1>
    <p class="muted">
      <router-link to="/pages">← Все страницы</router-link>
      <template v-if="!isNew && page.slug">
        ·
        <a :href="`${SITE_URL}/${page.slug}`" target="_blank" rel="noopener">Открыть на сайте</a>
      </template>
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty" class="alert ok">Изменения сохранены и уже видны на сайте.</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <div class="filters">
        <div class="filters-grid">
          <label class="field">
            <span class="field-label">Название (для админки и меню)</span>
            <input v-model="page.title" type="text" style="min-width: 320px">
          </label>
          <label class="field">
            <span class="field-label">Адрес страницы</span>
            <input v-model="page.slug" type="text" placeholder="payways" style="min-width: 180px">
          </label>
          <label class="field">
            <span class="field-label">Показывать на сайте</span>
            <span><input v-model="page.is_active" type="checkbox"> страница доступна</span>
          </label>
        </div>
        <div class="filters-grid" style="margin-top: 12px">
          <label class="field">
            <span class="field-label">Заголовок вкладки (title)</span>
            <input v-model="page.meta_title" type="text" style="min-width: 520px">
          </label>
          <label class="field">
            <span class="field-label">Описание (description)</span>
            <input v-model="page.meta_description" type="text" style="min-width: 520px">
          </label>
        </div>
      </div>

      <div class="editor-head">
        <div class="tabs">
          <button
            type="button"
            class="tab"
            :class="{ active: mode === 'visual' }"
            @click="mode = 'visual'"
          >Визуально</button>
          <button
            type="button"
            class="tab"
            :class="{ active: mode === 'html' }"
            @click="mode = 'html'"
          >HTML</button>
        </div>
        <button type="button" class="btn small" @click="filesOpen = true">Файлы сайта</button>
      </div>

      <HtmlEditor
        v-show="mode === 'visual'"
        ref="visualEditor"
        v-model="page.content"
        @pick-file="filesOpen = true"
      />
      <CodeEditor v-if="mode === 'html'" v-model="page.content" />

      <p class="edit-actions">
        <button type="button" class="btn primary" :disabled="saving || !dirty" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
        <span v-if="dirty" class="muted">есть несохранённые изменения</span>
      </p>
    </template>

    <FileManager v-if="filesOpen" @close="filesOpen = false" @pick="pickFile" />
  </div>
</template>
