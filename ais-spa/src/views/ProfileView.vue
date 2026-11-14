<script setup>
/**
 * Свой профиль: сведения, пароль, код подтверждения опасных действий и
 * токен быстрого входа. Все три секрета меняет сам сотрудник — для каждого
 * нужен текущий пароль.
 */
import { computed, onMounted, ref } from 'vue'
import {
  changeConfirmCode, changeFastToken, changePassword,
  deleteFastToken, fetchFastToken, saveProfile,
} from '../api'
import { employee, setEmployee } from '../auth'

const SITE = window.location.origin

/* Сведения */
const profile = ref({ full_name: '', position: '', email: '', phone: '' })
const profileMsg = ref('')
const profileErr = ref('')
const profileBusy = ref(false)

/* Пароль */
const pwd = ref({ current: '', next: '', repeat: '' })
const pwdMsg = ref('')
const pwdErr = ref('')
const pwdBusy = ref(false)

/* Код подтверждения */
const codeForm = ref({ current: '', code: '', repeat: '' })
const codeMsg = ref('')
const codeErr = ref('')
const codeBusy = ref(false)

/* Токен быстрого входа */
const tokenForm = ref({ current: '', token: '' })
const tokenValue = ref('')
const tokenNetworks = ref([])
const tokenMsg = ref('')
const tokenErr = ref('')
const tokenBusy = ref(false)

const hasToken = computed(() => employee.value?.has_fast_token)
const tokenLink = computed(() => (tokenValue.value ? `${SITE}/?token=${tokenValue.value}` : ''))

async function saveInfo() {
  profileBusy.value = true
  profileErr.value = ''
  profileMsg.value = ''
  try {
    setEmployee((await saveProfile(profile.value)).employee)
    profileMsg.value = 'Сохранено'
  } catch (e) {
    profileErr.value = e.message
  } finally {
    profileBusy.value = false
  }
}

async function savePassword() {
  pwdErr.value = ''
  pwdMsg.value = ''

  if (pwd.value.next !== pwd.value.repeat) {
    pwdErr.value = 'Новый пароль и его повтор не совпадают.'

    return
  }

  pwdBusy.value = true
  try {
    await changePassword(pwd.value.current, pwd.value.next)
    pwd.value = { current: '', next: '', repeat: '' }
    pwdMsg.value = 'Пароль изменён. Остальные ваши сессии закрыты.'
  } catch (e) {
    pwdErr.value = e.message
  } finally {
    pwdBusy.value = false
  }
}

async function saveCode() {
  codeErr.value = ''
  codeMsg.value = ''

  if (codeForm.value.code !== codeForm.value.repeat) {
    codeErr.value = 'Код и его повтор не совпадают.'

    return
  }

  codeBusy.value = true
  try {
    await changeConfirmCode(codeForm.value.current, codeForm.value.code)
    codeForm.value = { current: '', code: '', repeat: '' }
    codeMsg.value = 'Код подтверждения изменён'
    setEmployee({ ...employee.value, has_confirm_code: true })
  } catch (e) {
    codeErr.value = e.message
  } finally {
    codeBusy.value = false
  }
}

async function showToken() {
  tokenErr.value = ''
  try {
    const data = await fetchFastToken()
    tokenValue.value = data.token || ''
    tokenNetworks.value = data.networks || []
  } catch (e) {
    tokenErr.value = e.message
  }
}

async function saveToken() {
  tokenBusy.value = true
  tokenErr.value = ''
  tokenMsg.value = ''
  try {
    const data = await changeFastToken(tokenForm.value.current, tokenForm.value.token)
    tokenValue.value = data.token
    tokenForm.value = { current: '', token: '' }
    tokenMsg.value = 'Токен выдан'
    setEmployee({ ...employee.value, has_fast_token: true })
  } catch (e) {
    tokenErr.value = e.message
  } finally {
    tokenBusy.value = false
  }
}

async function dropToken() {
  if (!window.confirm('Отключить токен быстрого входа? Ссылки с ним перестанут работать.')) return

  tokenErr.value = ''
  tokenMsg.value = ''
  try {
    await deleteFastToken()
    tokenValue.value = ''
    tokenMsg.value = 'Токен отключён'
    setEmployee({ ...employee.value, has_fast_token: false })
  } catch (e) {
    tokenErr.value = e.message
  }
}

onMounted(() => {
  document.title = 'Профиль · АИС'
  const emp = employee.value || {}
  profile.value = {
    full_name: emp.full_name || '',
    position: emp.position || '',
    email: emp.email || '',
    phone: emp.phone || '',
  }
  if (emp.has_fast_token) showToken()
})
</script>

<template>
  <div class="narrow">
    <h1>Профиль</h1>
    <p class="muted">
      Логин — <b>{{ employee?.login }}</b>. Логин меняет администратор АИС.
    </p>

    <section class="panel">
      <h2>Сведения о себе</h2>
      <div v-if="profileErr" class="alert">{{ profileErr }}</div>
      <div v-if="profileMsg" class="alert ok">{{ profileMsg }}</div>

      <div class="form-grid">
        <label class="field">
          <span class="field-label">ФИО</span>
          <input v-model="profile.full_name" type="text" />
        </label>
        <label class="field">
          <span class="field-label">Должность</span>
          <input v-model="profile.position" type="text" />
        </label>
        <label class="field">
          <span class="field-label">E-mail</span>
          <input v-model="profile.email" type="email" />
        </label>
        <label class="field">
          <span class="field-label">Телефон</span>
          <input v-model="profile.phone" type="text" />
        </label>
      </div>

      <p><button class="btn primary" type="button" :disabled="profileBusy" @click="saveInfo">Сохранить</button></p>
    </section>

    <section class="panel">
      <h2>Пароль</h2>
      <p class="muted">
        Состав знаков не проверяем: подойдёт фраза из нескольких слов одного регистра —
        её и запомнить проще, и подобрать труднее, чем «Пароль123!».
      </p>
      <div v-if="pwdErr" class="alert">{{ pwdErr }}</div>
      <div v-if="pwdMsg" class="alert ok">{{ pwdMsg }}</div>

      <div class="form-grid">
        <label class="field">
          <span class="field-label">Текущий пароль</span>
          <input v-model="pwd.current" type="password" autocomplete="current-password" />
        </label>
        <label class="field">
          <span class="field-label">Новый пароль</span>
          <input v-model="pwd.next" type="password" autocomplete="new-password" />
        </label>
        <label class="field">
          <span class="field-label">Повторите новый</span>
          <input v-model="pwd.repeat" type="password" autocomplete="new-password" />
        </label>
      </div>

      <p>
        <button class="btn primary" type="button"
          :disabled="pwdBusy || !pwd.current || !pwd.next" @click="savePassword">Сменить пароль</button>
      </p>
    </section>

    <section class="panel">
      <h2>Код подтверждения опасных действий</h2>
      <p class="muted">
        Его спрашивают перед тем, что нельзя отменить: возврат денег за билет, удаление,
        сброс чужого пароля. От 3 до 20 любых знаков — состав не проверяем.
        <template v-if="!employee?.has_confirm_code">
          <br /><b>Сейчас код не задан — такие действия у вас не пройдут.</b>
        </template>
      </p>
      <div v-if="codeErr" class="alert">{{ codeErr }}</div>
      <div v-if="codeMsg" class="alert ok">{{ codeMsg }}</div>

      <div class="form-grid">
        <label class="field">
          <span class="field-label">Текущий пароль</span>
          <input v-model="codeForm.current" type="password" autocomplete="current-password" />
        </label>
        <label class="field">
          <span class="field-label">Код</span>
          <input v-model="codeForm.code" type="password" autocomplete="off" />
        </label>
        <label class="field">
          <span class="field-label">Повторите код</span>
          <input v-model="codeForm.repeat" type="password" autocomplete="off" />
        </label>
      </div>

      <p>
        <button class="btn primary" type="button"
          :disabled="codeBusy || !codeForm.current || !codeForm.code" @click="saveCode">Сохранить код</button>
      </p>
    </section>

    <section class="panel">
      <h2>Токен быстрого входа</h2>
      <p class="muted">
        Ссылка с токеном открывает АИС без ввода пароля. Токен действует
        <b>только из рабочей сети</b><template v-if="tokenNetworks.length"> ({{ tokenNetworks.join(', ') }})</template>:
        он виден в адресной строке и в журналах, поэтому снаружи не работает вообще.
      </p>
      <div v-if="tokenErr" class="alert">{{ tokenErr }}</div>
      <div v-if="tokenMsg" class="alert ok">{{ tokenMsg }}</div>

      <template v-if="hasToken && tokenValue">
        <div class="form-grid">
          <label class="field field-wide">
            <span class="field-label">Ссылка для входа</span>
            <input :value="tokenLink" type="text" readonly @focus="(e) => e.target.select()" />
          </label>
        </div>
      </template>

      <div class="form-grid">
        <label class="field">
          <span class="field-label">Текущий пароль</span>
          <input v-model="tokenForm.current" type="password" autocomplete="current-password" />
        </label>
        <label class="field">
          <span class="field-label">Свой токен (необязательно)</span>
          <input v-model="tokenForm.token" type="text" placeholder="латиница и цифры, 15–30 знаков" />
        </label>
      </div>

      <p class="row-actions">
        <button class="btn primary" type="button" :disabled="tokenBusy || !tokenForm.current" @click="saveToken">
          {{ hasToken ? 'Выдать новый токен' : 'Выдать токен' }}
        </button>
        <button v-if="hasToken" class="btn" type="button" @click="dropToken">Отключить токен</button>
      </p>
    </section>
  </div>
</template>
