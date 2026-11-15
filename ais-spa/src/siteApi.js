// Админское API сайта (backend avtovokzal57, сервис web). В dev '/site-api'
// проксируется vite-сервером, см. vite.config.js. Здесь — страницы, новости,
// блоки, меню, настройки и файлы: в legacy всё это правилось прямо в
// исходниках сайта (url/<slug>.php, vidjets/*.php, списки ссылок в
// index.php, файлы в docs по FTP).
//
// Эти маршруты тоже требуют сотрудника АИС с нужным правом: backend читает
// его из БД ais по той же cookie сессии (см. App\Services\AisAuth).
// Разбор ошибок, 401 и код подтверждения — общие, в http.js.
import { json, request as req } from './http'

const API = '/site-api/v1'

/* Страницы сайта */

export async function fetchPages() {
  return (await req(`${API}/admin/pages`)).data
}

export async function fetchPage(id) {
  return (await req(`${API}/admin/pages/${id}`)).data
}

export async function createPage(page) {
  return (await req(`${API}/admin/pages`, json('POST', page))).data
}

export async function updatePage(id, page) {
  return (await req(`${API}/admin/pages/${id}`, json('PUT', page))).data
}

export async function deletePage(id) {
  return req(`${API}/admin/pages/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

/* Новости сайта */

export async function fetchNewsList() {
  return (await req(`${API}/admin/news`)).data
}

export async function fetchNewsItem(id) {
  return (await req(`${API}/admin/news/${id}`)).data
}

export async function createNews(news) {
  return (await req(`${API}/admin/news`, json('POST', news))).data
}

export async function updateNews(id, news) {
  return (await req(`${API}/admin/news/${id}`, json('PUT', news))).data
}

export async function deleteNews(id) {
  return req(`${API}/admin/news/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

/* Блоки сайта («виджеты» legacy vidjets/*.php) */

export async function fetchBlocks() {
  return (await req(`${API}/admin/blocks`)).data
}

export async function saveBlocks(blocks) {
  return (await req(`${API}/admin/blocks`, json('PUT', { blocks }))).data
}

/* Настройки сайта: продажа и возврат билетов */

export async function fetchSettings() {
  return (await req(`${API}/admin/settings`)).data
}

export async function saveSettings(settings) {
  return (await req(`${API}/admin/settings`, json('PUT', { settings }))).data
}

/* Обновление данных сайта: расписание и пункты назначения */

export async function fetchSyncStatus() {
  return (await req(`${API}/admin/sync`)).data
}

export async function runSync(what, awtcod = null) {
  return (await req(`${API}/admin/sync/${what}`, json('POST', awtcod ? { awtcod } : {}))).data
}

/* Меню сайта */

export async function fetchMenu() {
  return (await req(`${API}/admin/menu`)).data
}

export async function saveMenu(menu) {
  return (await req(`${API}/admin/menu`, json('PUT', menu))).data
}

/* Файлы страниц (PDF документов, картинки) */

export async function fetchUploads() {
  return (await req(`${API}/admin/uploads`)).data
}

export async function uploadFile(file, dir = 'docs') {
  const form = new FormData()
  form.append('file', file)
  form.append('dir', dir)

  return (await req(`${API}/admin/uploads`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    body: form,
  })).data
}

export async function deleteUpload(path) {
  return req(`${API}/admin/uploads`, json('DELETE', { path }))
}

/* Обращения с сайта (форма «Напишите нам») */

export async function fetchFeedback(params = {}) {
  const query = new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined),
  )

  return (await req(`${API}/admin/feedback?${query}`)).data
}

export async function fetchFeedbackMessage(id) {
  return (await req(`${API}/admin/feedback/${id}`)).data
}

export async function updateFeedbackMessage(id, payload) {
  return (await req(`${API}/admin/feedback/${id}`, json('PUT', payload))).data
}

export async function deleteFeedbackMessage(id) {
  return req(`${API}/admin/feedback/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

/* Антиспам-словарь формы обратной связи */

export async function fetchSpamRules() {
  return (await req(`${API}/admin/spam-rules`)).data
}

export async function saveSpamRules(rules) {
  return (await req(`${API}/admin/spam-rules`, json('PUT', { rules }))).data
}
