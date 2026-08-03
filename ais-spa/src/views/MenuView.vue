<script setup>
/**
 * Меню сайта: верхнее (с выпадающим разделом «Пассажирам») и подвал.
 * В legacy оба списка были захардкожены в вёрстке avtovokzal57/index.php —
 * добавить страницу в меню мог только разработчик.
 */
import { computed, onMounted, ref } from 'vue'
import { fetchMenu, fetchPages, saveMenu } from '../siteApi'

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const header = ref([])
const footer = ref([])
const pages = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const snapshot = ref('')
let seq = 0

const dirty = computed(() => snapshot.value !== JSON.stringify(payload()))

/** Адреса для подсказки: страницы из базы плюс разделы с собственной логикой. */
const knownUrls = computed(() => [
  '/', '/raspisanie-avtobusov', '/backbycode', '/contacts', '/login',
  ...pages.value.map((p) => `/${p.slug}`),
])

function payload() {
  return {
    header: header.value.map((i) => ({
      title: i.title, url: i.url, group: i.group || null, is_active: i.is_active,
    })),
    footer: footer.value.map((i) => ({
      title: i.title, url: i.url, is_active: i.is_active,
    })),
  }
}

function fill(data) {
  header.value = data.header.map((i) => ({ uid: ++seq, ...i, group: i.group || '' }))
  footer.value = data.footer.map((i) => ({ uid: ++seq, ...i }))
  snapshot.value = JSON.stringify(payload())
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [menu, list] = await Promise.all([fetchMenu(), fetchPages()])
    pages.value = list
    fill(menu)
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
    fill(await saveMenu(payload()))
    saved.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

// Списки адресуем по имени, а не ссылкой на ref: в шаблоне Vue разворачивает
// ref, и в функцию пришёл бы массив без .value.
function listByName(name) {
  return name === 'header' ? header : footer
}

function add(name) {
  listByName(name).value.push({ uid: ++seq, title: '', url: '', group: '', is_active: true })
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

onMounted(() => {
  document.title = 'Меню сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Меню сайта</h1>
    <p class="muted">
      Ссылки в шапке и подвале <a :href="SITE_URL" target="_blank" rel="noopener">сайта</a>.
      Порядок строк здесь = порядок ссылок на сайте. Пункты шапки с одинаковым разделом
      собираются в выпадающее меню (в старом сайте это «Пассажирам»); на телефоне такой
      раздел показывается списком.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty" class="alert ok">Меню сохранено и уже видно на сайте.</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <h2>Верхнее меню</h2>
      <table class="menu-table">
        <thead>
          <tr>
            <th style="width: 26%">Название</th>
            <th style="width: 30%">Адрес</th>
            <th style="width: 20%">Раздел</th>
            <th style="width: 10%">На сайте</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, index) in header" :key="item.uid">
            <td><input v-model="item.title" type="text"></td>
            <td><input v-model="item.url" type="text" list="known-urls"></td>
            <td><input v-model="item.group" type="text" placeholder="без раздела"></td>
            <td class="menu-center"><input v-model="item.is_active" type="checkbox"></td>
            <td class="menu-actions">
              <button type="button" class="btn small" title="Выше" @click="move('header', index, -1)">↑</button>
              <button type="button" class="btn small" title="Ниже" @click="move('header', index, 1)">↓</button>
              <button type="button" class="btn small" title="Удалить" @click="remove('header', index)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p><button type="button" class="btn small" @click="add('header')">Добавить пункт</button></p>

      <h2>Меню в подвале</h2>
      <table class="menu-table">
        <thead>
          <tr>
            <th style="width: 26%">Название</th>
            <th style="width: 50%">Адрес</th>
            <th style="width: 10%">На сайте</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, index) in footer" :key="item.uid">
            <td><input v-model="item.title" type="text"></td>
            <td><input v-model="item.url" type="text" list="known-urls"></td>
            <td class="menu-center"><input v-model="item.is_active" type="checkbox"></td>
            <td class="menu-actions">
              <button type="button" class="btn small" title="Выше" @click="move('footer', index, -1)">↑</button>
              <button type="button" class="btn small" title="Ниже" @click="move('footer', index, 1)">↓</button>
              <button type="button" class="btn small" title="Удалить" @click="remove('footer', index)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p><button type="button" class="btn small" @click="add('footer')">Добавить пункт</button></p>

      <datalist id="known-urls">
        <option v-for="url in knownUrls" :key="url" :value="url" />
      </datalist>

      <p class="edit-actions">
        <button type="button" class="btn primary" :disabled="saving || !dirty" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить меню' }}
        </button>
        <span v-if="dirty" class="muted">есть несохранённые изменения</span>
      </p>
    </template>
  </div>
</template>
