<script setup>
/**
 * Код подтверждения опасного действия. Окно одно на всё приложение: его
 * открывает слой запросов, когда сервер отвечает `need_confirm` (возврат
 * денег, сброс чужих секретов, удаления), и повторяет запрос с кодом.
 *
 * У каждого сотрудника свой код — он задаётся в профиле. Это не второй
 * пароль, а подтверждение «я понимаю, что делаю»: такие действия нельзя
 * отменить, а выполняются они одним щелчком.
 */
import { nextTick, ref, watch } from 'vue'
import { cancelConfirmCode, confirmState, submitConfirmCode } from '../confirm'

const code = ref('')
const field = ref(null)

watch(confirmState, async (state) => {
  if (!state) return
  code.value = ''
  await nextTick()
  field.value?.focus()
})

function submit() {
  if (!code.value.trim()) return
  submitConfirmCode(code.value)
}
</script>

<template>
  <div v-if="confirmState" class="cc-backdrop" @click.self="cancelConfirmCode()">
    <form class="cc-dialog" @submit.prevent="submit">
      <div class="cc-title">Подтверждение действия</div>
      <p class="muted">
        Действие необратимо. Введите свой код подтверждения — тот, что задан в профиле.
      </p>

      <div v-if="confirmState.error" class="alert">{{ confirmState.error }}</div>

      <input ref="field" v-model="code" type="password" autocomplete="off" class="cc-input" />

      <div class="cc-actions">
        <button class="btn primary" type="submit" :disabled="!code.trim()">Подтвердить</button>
        <button class="btn" type="button" @click="cancelConfirmCode()">Отмена</button>
      </div>
    </form>
  </div>
</template>
