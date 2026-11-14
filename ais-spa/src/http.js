// Общий слой запросов АИС: один разбор ошибок на два API (ais-api отвечает
// {ok,…}, backend сайта — {success,…}) и одна обработка того, что приходит
// от авторизации сотрудника.
//
// Токен сессии в запрос НЕ подставляется: его несёт httpOnly-cookie,
// поставленная сервером (ais_session). SPA и оба API живут на одном домене,
// поэтому cookie уходит сама — fetch по умолчанию посылает её на
// same-origin запросах.
import { requestConfirmCode } from './confirm'

let onUnauthorized = () => {}

/** Кому сообщать, что сессия кончилась (auth.js показывает форму входа). */
export function setUnauthorizedHandler(handler) {
  onUnauthorized = handler
}

/**
 * Запрос к API. Опасные действия сервер не выполняет без кода подтверждения
 * (`need_confirm` в ответе) — код спрашиваем и повторяем запрос; этим
 * занимается слой запросов, а не каждая страница.
 */
export async function request(url, opts = {}, attempt = 0, confirmCode = null) {
  const headers = { ...(opts.headers || {}) }
  if (confirmCode) headers['X-Confirm-Code'] = confirmCode

  const r = await fetch(url, { ...opts, headers })

  let data = null
  try { data = await r.json() } catch (e) { /* не JSON */ }

  const failed = !r.ok || (data && (data.ok === false || data.success === false))
  if (!failed) return data

  if (data && data.unauthenticated) {
    onUnauthorized()
    throw new Error(data.error || 'Нужно войти в систему.')
  }

  // Код подтверждения: сначала его просто не было, потом — ввели неверно.
  // Трёх попыток достаточно; дальше человек сам закроет окно.
  if (data && data.need_confirm && attempt < 3) {
    const code = await requestConfirmCode(attempt === 0 ? '' : data.error)
    if (code === null) throw new Error('Действие отменено.')

    return request(url, opts, attempt + 1, code)
  }

  throw new Error(errorMessage(data, r.status))
}

function errorMessage(data, status) {
  if (!data) return `Ошибка запроса (HTTP ${status})`

  return data.error || firstValidationError(data) || data.message || `Ошибка запроса (HTTP ${status})`
}

/** Первая ошибка проверки Laravel — её и показываем пользователю. */
function firstValidationError(data) {
  const errors = data.errors
  if (!errors || typeof errors !== 'object') return null

  for (const messages of Object.values(errors)) {
    if (Array.isArray(messages) && messages.length) return messages[0]
  }

  return null
}

/** Тело запроса в JSON — одинаково у обоих API. */
export function json(method, body) {
  return {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  }
}
