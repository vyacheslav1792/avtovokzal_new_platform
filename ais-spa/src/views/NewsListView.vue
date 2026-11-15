<script setup>
/**
 * Список новостей и объявлений сайта.
 *
 * В legacy раздела новостей не было: объявления («День поля», заказной рейс
 * до Спасского-Лутовинова) лежали вперемешку с вёрсткой в
 * avtovokzal57/vidjets/news.php, срок показа задавался условием
 * time() < strtotime('…') в самом исходнике, а «архив» — закомментированными
 * блоками в том же файле. Каждая новость означала выкладку кода на бой.
 */
import { onMounted, ref } from 'vue'
import { deleteNews, fetchNewsList } from '../siteApi'
import { can } from '../auth'

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const news = ref([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    news.value = await fetchNewsList()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function remove(item) {
  if (!window.confirm(`Удалить новость «${item.title}»? Прежние ссылки на неё перестанут работать — устаревшую новость лучше выключить.`)) return

  error.value = ''
  try {
    await deleteNews(item.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

function day(value) {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}

/** Срок показа человеческой строкой: пусто — показывается бессрочно. */
function period(item) {
  if (!item.publish_from && !item.publish_to) return 'бессрочно'
  if (item.publish_from && item.publish_to) return `${day(item.publish_from)} — ${day(item.publish_to)}`

  return item.publish_from ? `с ${day(item.publish_from)}` : `до ${day(item.publish_to)}`
}

onMounted(() => {
  document.title = 'Новости сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Новости сайта</h1>
    <p class="muted">
      Объявления на сайте: изменения в расписании, заказные рейсы, перевозки до мест
      проведения мероприятий. Новость можно показывать ограниченный срок — по его
      окончании она исчезает с сайта сама.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <p>
        <router-link v-if="can('news.edit')" class="btn primary" to="/news/new">Новая новость</router-link>
        <a class="btn" :href="`${SITE_URL}/news`" target="_blank" rel="noopener" style="margin-left: 8px">
          Раздел на сайте
        </a>
      </p>

      <table class="pages-table">
        <thead>
          <tr>
            <th />
            <th>Заголовок</th>
            <th>Дата</th>
            <th>Показ</th>
            <th>На главной</th>
            <th>Состояние</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in news" :key="item.id">
            <td class="news-thumb-cell">
              <img v-if="item.cover_url" class="news-thumb" :src="`${SITE_URL}${item.cover_url}`" alt="" loading="lazy">
            </td>
            <td>
              <router-link :to="`/news/${item.id}`">{{ item.title }}</router-link>
              <div class="muted news-slug">/news/{{ item.slug }}</div>
            </td>
            <td>{{ day(item.published_at) }}</td>
            <td>{{ period(item) }}</td>
            <td class="menu-center">{{ item.on_index ? 'да' : '—' }}</td>
            <td>
              <!-- Важно именно «видно сейчас»: включённая новость с прошедшим
                   сроком на сайте не появится, и по галочке этого не понять. -->
              <span :class="item.visible ? 'badge-on' : 'badge-off'">
                {{ item.visible ? 'на сайте' : (item.is_active ? 'вне срока' : 'выключена') }}
              </span>
            </td>
            <td class="pages-actions">
              <a
                v-if="item.visible"
                class="btn small"
                :href="`${SITE_URL}/news/${item.slug}`"
                target="_blank"
                rel="noopener"
              >Открыть</a>
              <router-link class="btn small" :to="`/news/${item.id}`">
                {{ can('news.edit') ? 'Править' : 'Открыть' }}
              </router-link>
              <button v-if="can('news.delete')" type="button" class="btn small" @click="remove(item)">Удалить</button>
            </td>
          </tr>
          <tr v-if="!news.length">
            <td colspan="7" class="muted">Новостей пока нет</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>
