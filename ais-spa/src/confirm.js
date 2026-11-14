// Код подтверждения опасного действия: общее состояние окна, которое
// рисует ConfirmCodeDialog (оно одно на всё приложение, в макете).
//
// Какие действия опасны, решает сервер (`confirm` модуля в
// shared/ais_permissions.php): он отвечает `need_confirm`, слой запросов
// спрашивает код и повторяет запрос. Страницам об этом знать не нужно.
import { ref } from 'vue'

export const confirmState = ref(null)

/**
 * Спросить код. Возвращает введённый код либо null, если отказались.
 *
 * @param {string} error сообщение сервера (например «Код неверен»)
 */
export function requestConfirmCode(error = '') {
  return new Promise((resolve) => {
    confirmState.value = { error, resolve }
  })
}

export function submitConfirmCode(code) {
  const state = confirmState.value
  confirmState.value = null
  state?.resolve(code)
}

export function cancelConfirmCode() {
  const state = confirmState.value
  confirmState.value = null
  state?.resolve(null)
}
