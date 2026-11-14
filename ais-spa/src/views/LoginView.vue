<script setup>
/**
 * Вход в АИС. В legacy входа не было вовсе: доступ закрывало ограничение
 * по IP на nginx, и внутри сети отчёты и возврат билета открывал кто угодно
 * с любой машины. Теперь вход по логину и паролю — а ограничение по IP
 * снято, АИС открывается и снаружи.
 */
import { nextTick, onMounted, ref } from 'vue'
import { authError, signIn } from '../auth'

const login = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)
const loginField = ref(null)

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await signIn(login.value, password.value)
  } catch (e) {
    error.value = e.message
    password.value = ''
    await nextTick()
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  document.title = 'Вход · АИС'
  loginField.value?.focus()
})
</script>

<template>
  <div class="login-screen">
    <form class="login-card" @submit.prevent="submit">
      <div class="login-title">АИС автовокзалов</div>
      <p class="muted login-sub">Отчёты и администрирование</p>

      <!-- Причина неудачного входа по токену из адреса: сам токен мог
           устареть, либо мы не в рабочей сети. -->
      <div v-if="authError && !error" class="alert warn">{{ authError }}</div>
      <div v-if="error" class="alert">{{ error }}</div>

      <label class="login-field">
        <span class="field-label">Логин</span>
        <input ref="loginField" v-model="login" type="text" autocomplete="username" autocapitalize="off" spellcheck="false" />
      </label>

      <label class="login-field">
        <span class="field-label">Пароль</span>
        <input v-model="password" type="password" autocomplete="current-password" />
      </label>

      <button class="btn primary login-submit" type="submit" :disabled="busy || !login.trim() || !password">
        {{ busy ? 'Проверяем…' : 'Войти' }}
      </button>

      <p class="muted login-hint">
        Логин нечувствителен к регистру. Пароль забыт — его сбросит администратор АИС.
      </p>
    </form>
  </div>
</template>
