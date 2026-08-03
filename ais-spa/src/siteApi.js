// Админское API сайта (backend avtovokzal57, сервис web). В dev '/site-api'
// проксируется vite-сервером, см. vite.config.js. Здесь — страницы, меню
// и файлы: в legacy всё это правилось прямо в исходниках сайта
// (url/<slug>.php, списки ссылок в index.php, файлы в docs по FTP).
const API = '/site-api/v1'

async function req(url, opts) {
  const r = await fetch(url, opts)
  let data = null
  try { data = await r.json() } catch (e) { /* не JSON */ }
  if (!r.ok || (data && data.success === false)) {
    const msg = (data && (data.error || firstValidationError(data))) || `Ошибка запроса (HTTP ${r.status})`
    throw new Error(msg)
  }
  return data
}

/** Первая ошибка валидации Laravel — её и показываем пользователю. */
function firstValidationError(data) {
  const errors = data && data.errors
  if (!errors || typeof errors !== 'object') return null
  for (const messages of Object.values(errors)) {
    if (Array.isArray(messages) && messages.length) return messages[0]
  }
  return data.message || null
}

function json(method, body) {
  return {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  }
}

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
