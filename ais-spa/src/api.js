// Единая обёртка над API отчётов новой платформы (ais-api, Laravel).
// Контракт ответов тот же, что был у legacy apiv2: {ok, reports|meta|sections...}.
// В dev '/api' проксируется vite-сервером на ais_web (см. vite.config.js).
const API = '/api/v1'

async function req(url, opts) {
  const r = await fetch(url, opts)
  let data = null
  try { data = await r.json() } catch (e) { /* не JSON */ }
  if (!r.ok || (data && data.ok === false)) {
    const msg = (data && data.error) ? data.error : `Ошибка запроса (HTTP ${r.status})`
    throw new Error(msg)
  }
  return data
}

export function fetchReports() {
  return req(`${API}/reports`)
}

export function fetchMeta(slug) {
  return req(`${API}/reports/${encodeURIComponent(slug)}/meta`)
}

export function runReport(slug, filters) {
  return req(`${API}/reports/${encodeURIComponent(slug)}/run`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(filters),
  })
}
