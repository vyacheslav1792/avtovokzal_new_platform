// Кто вошёл в АИС и что ему доступно.
//
// Токен сессии SPA у себя не хранит: его несёт httpOnly-cookie, которую
// ставит сервер при входе. Это не прихоть — сессия АИС живёт месяцами, а
// localStorage и cookie, записанные из JS, Safari/ITP стирает примерно через
// неделю, и сотрудник разлогинивался бы сам собой (та же причина, что у
// кабинета сайта). Поэтому «вошёл или нет» выясняется у сервера —
// GET /auth/me при запуске приложения.
import { computed, reactive } from 'vue'
import * as api from './api'
import { setUnauthorizedHandler } from './http'

// Токен быстрого входа снимаем из адреса СРАЗУ при загрузке модуля — до
// того, как приложение смонтируется и vue-router запишет свою историю:
// после его инициализации наш replaceState затирается, и токен остаётся
// в адресной строке, в истории браузера и в заголовке Referer.
const fastToken = takeTokenFromUrl()

const state = reactive({
  employee: null,
  // Пока первый /auth/me не ответил, показывать нечего: иначе форма входа
  // мигала бы на каждой перезагрузке у уже вошедшего.
  ready: false,
  error: '',
})

export const employee = computed(() => state.employee)
export const authReady = computed(() => state.ready)
export const authError = computed(() => state.error)
export const isAuthenticated = computed(() => state.employee !== null)

/** Право вида "<модуль>.<право>"; у администратора есть всё. */
export function can(permission) {
  const emp = state.employee
  if (!emp) return false

  return emp.is_admin || (emp.permissions || []).includes(permission)
}

/** Есть ли у сотрудника хоть одно право модуля (нужно для пунктов меню). */
export function canAny(...permissions) {
  return permissions.some((permission) => can(permission))
}

/**
 * Поднять состояние при запуске. Токен быстрого входа из адреса (он уже
 * снят, см. выше) обмениваем на обычную сессию; иначе спрашиваем сервер,
 * жива ли сессия из cookie.
 */
export async function bootstrap() {
  if (fastToken) {
    try {
      const data = await api.loginByToken(fastToken)
      state.employee = data.employee
      state.ready = true

      return
    } catch (e) {
      // Токен мог быть изменён или мы не в рабочей сети — покажем причину
      // над формой входа, а не вместо неё.
      state.error = e.message
    }
  }

  try {
    state.employee = (await api.fetchMe()).employee
  } catch (e) {
    state.employee = null
  } finally {
    state.ready = true
  }
}

export async function signIn(login, password) {
  const data = await api.login(login, password)
  state.employee = data.employee
  state.error = ''
}

export async function signOut() {
  try {
    await api.logout()
  } finally {
    state.employee = null
  }
}

/** Обновить сведения о себе после правки профиля. */
export function setEmployee(data) {
  state.employee = data
}

export async function refresh() {
  state.employee = (await api.fetchMe()).employee
}

// Сессия кончилась посреди работы (её мог погасить администратор) — любой
// запрос вернёт `unauthenticated`, и мы сразу показываем форму входа.
setUnauthorizedHandler(() => {
  state.employee = null
  state.ready = true
})

/** Вынуть `?token=` из адреса и убрать его оттуда. */
function takeTokenFromUrl() {
  const url = new URL(window.location.href)
  const token = url.searchParams.get('token')

  if (token === null) return null

  url.searchParams.delete('token')
  window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash)

  return token
}
