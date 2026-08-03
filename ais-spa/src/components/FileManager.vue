<script setup>
/**
 * Файлы страниц сайта: PDF регламентирующих документов, перечень 223-ФЗ,
 * плакаты о мошенничестве. В legacy их клали в avtovokzal57/docs по FTP,
 * а ссылки в тексте вели на /docs/<файл> — эти адреса сохранены, поэтому
 * загруженный здесь файл доступен по тому же виду ссылки.
 */
import { computed, onMounted, ref } from 'vue'
import { deleteUpload, fetchUploads, uploadFile } from '../siteApi'

const emit = defineEmits(['close', 'pick'])

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const files = ref([])
const filter = ref('')
const loading = ref(false)
const uploading = ref(false)
const error = ref('')
const input = ref(null)

const visible = computed(() => {
  const needle = filter.value.trim().toLowerCase()
  return needle ? files.value.filter((f) => f.path.toLowerCase().includes(needle)) : files.value
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    files.value = (await fetchUploads()).files
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function upload(event) {
  const list = Array.from(event.target.files || [])
  if (!list.length) return

  uploading.value = true
  error.value = ''
  try {
    for (const file of list) await uploadFile(file)
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    uploading.value = false
    if (input.value) input.value.value = ''
  }
}

async function remove(file) {
  if (!window.confirm(`Удалить файл ${file.path}? Ссылки на него на страницах перестанут работать.`)) return

  error.value = ''
  try {
    await deleteUpload(file.path)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

function size(bytes) {
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}

function date(value) {
  return new Date(value).toLocaleDateString('ru-RU')
}

onMounted(load)
</script>

<template>
  <div class="fm-backdrop" @click.self="emit('close')">
    <div class="fm-dialog">
      <div class="fm-head">
        <b>Файлы сайта</b>
        <button type="button" class="btn small" @click="emit('close')">Закрыть</button>
      </div>

      <div class="fm-tools">
        <input v-model="filter" class="fm-filter" type="search" placeholder="Поиск по имени">
        <label class="btn small primary fm-upload">
          {{ uploading ? 'Загрузка…' : 'Загрузить файл' }}
          <input ref="input" type="file" multiple hidden :disabled="uploading" @change="upload">
        </label>
      </div>

      <div v-if="error" class="alert">{{ error }}</div>
      <div v-if="loading" class="muted loading">Загрузка…</div>

      <table v-else class="fm-table">
        <thead>
          <tr>
            <th>Файл</th>
            <th>Размер</th>
            <th>Загружен</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="file in visible" :key="file.path">
            <td>
              <a :href="`${SITE_URL}${file.url}`" target="_blank" rel="noopener">{{ file.url }}</a>
            </td>
            <td class="fm-num">{{ size(file.size) }}</td>
            <td class="fm-num">{{ date(file.modified_at) }}</td>
            <td class="fm-actions">
              <button type="button" class="btn small primary" @click="emit('pick', file)">Вставить</button>
              <button type="button" class="btn small" @click="remove(file)">Удалить</button>
            </td>
          </tr>
          <tr v-if="!visible.length">
            <td colspan="4" class="muted">Файлов нет</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
