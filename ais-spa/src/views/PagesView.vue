<script setup>
/**
 * Список обычных страниц сайта (перенос legacy avtovokzal57/url/<slug>.php:
 * оплата, документы, FAQ, соглашение и политика ПД, услуги, 223-ФЗ,
 * противодействие коррупции и мошенничеству). Раньше их правили в
 * исходниках и выкладывали на боевой сервер.
 */
import { onMounted, ref } from 'vue'
import { deletePage, fetchPages } from '../siteApi'

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const pages = ref([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    pages.value = await fetchPages()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function remove(page) {
  if (!window.confirm(`Удалить страницу «${page.title}»? Ссылки на неё в меню останутся — уберите их в разделе «Меню сайта».`)) return

  error.value = ''
  try {
    await deletePage(page.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

function updated(value) {
  return value ? new Date(value).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}

onMounted(() => {
  document.title = 'Страницы сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Страницы сайта</h1>
    <p class="muted">
      Текстовые страницы автовокзал57: оплата, документы, ответы на вопросы, персональные данные,
      услуги, закупки. Содержимое правится здесь — визуально или в HTML.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <p>
        <router-link class="btn primary" to="/pages/new">Новая страница</router-link>
        <router-link class="btn" to="/menu" style="margin-left: 8px">Меню сайта</router-link>
      </p>

      <table class="pages-table">
        <thead>
          <tr>
            <th>Название</th>
            <th>Адрес</th>
            <th>Состояние</th>
            <th>Изменена</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="page in pages" :key="page.id">
            <td>
              <router-link :to="`/pages/${page.id}`">{{ page.title }}</router-link>
            </td>
            <td>
              <a :href="`${SITE_URL}/${page.slug}`" target="_blank" rel="noopener">/{{ page.slug }}</a>
            </td>
            <td>
              <span :class="page.is_active ? 'badge-on' : 'badge-off'">
                {{ page.is_active ? 'на сайте' : 'скрыта' }}
              </span>
            </td>
            <td>{{ updated(page.updated_at) }}</td>
            <td class="pages-actions">
              <router-link class="btn small" :to="`/pages/${page.id}`">Править</router-link>
              <button type="button" class="btn small" @click="remove(page)">Удалить</button>
            </td>
          </tr>
          <tr v-if="!pages.length">
            <td colspan="5" class="muted">Страниц пока нет</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>
