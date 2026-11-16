// API АИС (ais-api, Laravel). Контракт отчётов тот же, что был у legacy
// apiv2: {ok, reports|meta|sections…}. В dev '/api' проксируется
// vite-сервером на ais_web (см. vite.config.js).
//
// В отличие от legacy, доступ закрыт авторизацией сотрудника, а не только
// ограничением по IP на nginx: сессию несёт httpOnly-cookie, её ставит
// сервер при входе (см. http.js).
import { json, request } from './http'

const API = '/api/v1'

/* Вход и свои настройки */

export function login(loginName, password) {
  return request(`${API}/auth/login`, json('POST', { login: loginName, password }))
}

/** Обмен токена быстрого входа (из адреса `?token=`) на обычную сессию. */
export function loginByToken(token) {
  return request(`${API}/auth/token-login`, json('POST', { token }))
}

export function fetchMe() {
  return request(`${API}/auth/me`)
}

export function logout() {
  return request(`${API}/auth/logout`, { method: 'POST', headers: { Accept: 'application/json' } })
}

export function saveProfile(profile) {
  return request(`${API}/auth/profile`, json('PUT', profile))
}

export function changePassword(currentPassword, password) {
  return request(`${API}/auth/password`, json('POST', { current_password: currentPassword, password }))
}

export function changeConfirmCode(currentPassword, code) {
  return request(`${API}/auth/confirm-code`, json('PUT', { current_password: currentPassword, code }))
}

export function fetchFastToken() {
  return request(`${API}/auth/fast-token`)
}

export function changeFastToken(currentPassword, token) {
  return request(`${API}/auth/fast-token`, json('PUT', { current_password: currentPassword, token }))
}

export function deleteFastToken() {
  return request(`${API}/auth/fast-token`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

/* Отчёты */

export function fetchReports() {
  return request(`${API}/reports`)
}

export function fetchMeta(slug) {
  return request(`${API}/reports/${encodeURIComponent(slug)}/meta`)
}

export function runReport(slug, filters) {
  return request(`${API}/reports/${encodeURIComponent(slug)}/run`, json('POST', filters))
}

/* Справочник автостанций: подключения к базам BUSTER и выключатели.
   До 15.11.2026 это был файл (shared/stations.php), а в legacy — массив
   $AWT прямо в коде, причём в трёх местах. */

export function fetchStations() {
  return request(`${API}/stations`)
}

export function saveStation(awtcod, station) {
  return request(`${API}/stations/${awtcod}`, json('PUT', station))
}

export function createStation(station) {
  return request(`${API}/stations`, json('POST', station))
}

export function deleteStation(awtcod) {
  return request(`${API}/stations/${awtcod}`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

/** Быстрое переключение варианта подключения станции. */
export function switchStationProfile(awtcod, slot) {
  return request(`${API}/stations/${awtcod}/profile`, json('POST', { slot }))
}

/** Проверка связи с базой станции (slot — по умолчанию действующий). */
export function testStation(awtcod, slot) {
  return request(`${API}/stations/${awtcod}/test`, json('POST', slot ? { slot } : {}))
}

/** Разовый перенос станций из файла конфигурации в справочник. */
export function importStationsFromFile() {
  return request(`${API}/stations/import-file`, json('POST', {}))
}

/* Фискальный сервер (KkmServer): варианты подключения и переключение. */

export function fetchKkmServers() {
  return request(`${API}/kkm-servers`)
}

export function saveKkmServers(servers) {
  return request(`${API}/kkm-servers`, json('PUT', { servers }))
}

/** slot = null выключает фискализацию целиком. */
export function activateKkmServer(slot) {
  return request(`${API}/kkm-servers/activate`, json('POST', { slot }))
}

export function testKkmServer(slot) {
  return request(`${API}/kkm-servers/test`, json('POST', { slot }))
}

/* Сотрудники */

export function fetchEmployees() {
  return request(`${API}/employees`)
}

export function createEmployee(employee) {
  return request(`${API}/employees`, json('POST', employee))
}

export function updateEmployee(id, employee) {
  return request(`${API}/employees/${id}`, json('PUT', employee))
}

export function deleteEmployee(id) {
  return request(`${API}/employees/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

export function resetEmployeePassword(id, password) {
  return request(`${API}/employees/${id}/password`, json('POST', { password }))
}

export function resetEmployeeConfirmCode(id, code) {
  return request(`${API}/employees/${id}/confirm-code`, json('POST', { code }))
}

export function resetEmployeeFastToken(id, token) {
  return request(`${API}/employees/${id}/fast-token`, json('POST', { token }))
}

export function deleteEmployeeFastToken(id) {
  return request(`${API}/employees/${id}/fast-token`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}

/**
 * Работа от имени сотрудника: сервер выдаёт его короткую сессию, а свою
 * кладёт во вторую httpOnly-cookie — по ней и возвращаемся. Опасное
 * действие, поэтому сервер спросит код подтверждения (его спрашивает
 * общий слой запросов, см. http.js).
 */
export function impersonateEmployee(id) {
  return request(`${API}/employees/${id}/impersonate`, { method: 'POST', headers: { Accept: 'application/json' } })
}

/** Вернуться к себе. Права на это не нужно — нужна своя прежняя сессия. */
export function stopImpersonation() {
  return request(`${API}/impersonation/stop`, { method: 'POST', headers: { Accept: 'application/json' } })
}

export function logoutEmployeeSessions(id) {
  return request(`${API}/employees/${id}/logout`, { method: 'POST', headers: { Accept: 'application/json' } })
}

/* Роли и права */

export function fetchRoles() {
  return request(`${API}/roles`)
}

export function createRole(role) {
  return request(`${API}/roles`, json('POST', role))
}

export function updateRole(id, role) {
  return request(`${API}/roles/${id}`, json('PUT', role))
}

export function deleteRole(id) {
  return request(`${API}/roles/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } })
}
