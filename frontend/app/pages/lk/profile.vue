<script setup lang="ts">
import type { AuthCodeState } from '~/composables/useAuth'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ title: 'Профиль — личный кабинет' })

const { user, updateProfile, changePassword, requestEmail, confirmEmail, logout } = useAuth()
const route = useRoute()
const toast = useToast()

const phone = ref(formatPhone(user.value?.phone))
const name = ref(user.value?.name ?? '')
const savingProfile = ref(false)
const profileErrors = ref<Record<string, string>>({})

/*
 * Кабинет, заведённый через VK, может быть без почты: VK отдаёт адрес не
 * всегда. Тогда предлагаем его добавить — но не требуем (покупать это не
 * мешает, адрес для билетов вводится в форме покупки). Сразу записать
 * введённый адрес нельзя: по e-mail кабинет показывает покупки, сделанные
 * до регистрации, поэтому владение подтверждается КОДОМ из письма — он же
 * не уводит письмо в спам, в отличие от ссылки.
 */
const newEmail = ref('')
const savingEmail = ref(false)
const emailErrors = ref<Record<string, string>>({})
const emailSentTo = ref('')
const emailCodeState = ref<AuthCodeState | null>(null)
const emailCodeError = ref('')
// Пришли сразу после входа через VK — подсвечиваем блок с почтой.
const emailInvited = computed(() => queryFlag(route.query['add-email']))

const currentPassword = ref('')
const password = ref('')
const passwordRepeat = ref('')
const savingPassword = ref(false)
const passwordErrors = ref<Record<string, string>>({})

async function saveProfile() {
  profileErrors.value = {}
  savingProfile.value = true
  try {
    await updateProfile({ phone: phone.value, name: name.value })
    toast.add({ title: 'Профиль сохранён', color: 'success' })
  }
  catch (e: any) {
    profileErrors.value = apiFieldErrors(e)
    if (!Object.keys(profileErrors.value).length) {
      toast.add({ title: apiErrorMessage(e), color: 'error' })
    }
  }
  finally {
    savingProfile.value = false
  }
}

async function sendEmailConfirmation() {
  emailErrors.value = {}
  emailCodeError.value = ''
  savingEmail.value = true
  try {
    emailCodeState.value = await requestEmail(newEmail.value)
    emailSentTo.value = emailCodeState.value.email
    toast.add({ title: `Код отправлен на ${emailSentTo.value}`, color: 'success' })
  }
  catch (e: any) {
    emailErrors.value = apiFieldErrors(e)
    if (!Object.keys(emailErrors.value).length) {
      toast.add({ title: apiErrorMessage(e), color: 'error' })
    }
  }
  finally {
    savingEmail.value = false
  }
}

/** Код подтверждения адреса: ввод и повторная отправка. */
async function submitEmailCode(code: string) {
  emailCodeError.value = ''
  savingEmail.value = true
  try {
    const confirmed = await confirmEmail(emailSentTo.value, code)
    emailSentTo.value = ''
    emailCodeState.value = null
    newEmail.value = ''
    toast.add({ title: `Адрес ${confirmed} подтверждён`, color: 'success' })
  }
  catch (e: any) {
    emailCodeError.value = apiErrorMessage(e, 'Не удалось подтвердить адрес, попробуйте позже')
  }
  finally {
    savingEmail.value = false
  }
}

async function resendEmailCode() {
  emailCodeError.value = ''
  savingEmail.value = true
  try {
    emailCodeState.value = await requestEmail(emailSentTo.value)
  }
  catch (e: any) {
    emailCodeError.value = apiErrorMessage(e, 'Не удалось отправить код, попробуйте позже')
  }
  finally {
    savingEmail.value = false
  }
}

async function savePassword() {
  passwordErrors.value = {}

  if (password.value.trim() !== passwordRepeat.value.trim()) {
    passwordErrors.value.password = 'Пароль и подтверждение должны совпадать'
    return
  }

  savingPassword.value = true
  try {
    await changePassword(currentPassword.value, password.value)
    currentPassword.value = ''
    password.value = ''
    passwordRepeat.value = ''
    toast.add({ title: 'Пароль изменён', color: 'success' })
  }
  catch (e: any) {
    passwordErrors.value = apiFieldErrors(e)
    if (!Object.keys(passwordErrors.value).length) {
      toast.add({ title: apiErrorMessage(e), color: 'error' })
    }
  }
  finally {
    savingPassword.value = false
  }
}

async function signOut() {
  await logout()
  await navigateTo('/')
}
</script>

<template>
  <UContainer class="py-8 max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <h1 class="text-3xl font-light">Профиль</h1>
        <UBadge v-if="user?.has_vk" color="info" variant="subtle">Вход через VK</UBadge>
      </div>
      <div class="flex gap-2">
        <UButton to="/lk" color="neutral" variant="outline" icon="i-lucide-arrow-left">
          К моим билетам
        </UButton>
        <UButton color="neutral" variant="ghost" icon="i-lucide-log-out" @click="signOut">
          Выйти
        </UButton>
      </div>
    </div>

    <UCard>
      <template #header>
        <div class="font-medium">Контакты</div>
      </template>

      <form class="space-y-4" @submit.prevent="saveProfile">
        <UFormField
          v-if="user?.email"
          label="Электронная почта" help="E-mail — логин кабинета, на него приходят билеты"
        >
          <UInput :model-value="user.email" class="w-full" disabled />
        </UFormField>

        <UFormField
          label="Номер телефона" :error="profileErrors.phone"
          help="Необязательно. Подставляется при покупке; изменить его можно и в самой форме покупки"
        >
          <PhoneInput
            v-model="phone" class="w-full"
            :highlight="!!profileErrors.phone"
          />
        </UFormField>

        <UFormField label="Как к вам обращаться" :error="profileErrors.name">
          <UInput v-model="name" class="w-full" placeholder="Необязательно" />
        </UFormField>

        <UButton type="submit" color="error" :loading="savingProfile">Сохранить</UButton>
      </form>
    </UCard>

    <!-- Кабинет из VK без почты: предлагаем добавить, но не требуем. -->
    <UCard v-if="user && !user.email">
      <template #header>
        <div class="font-medium">Электронная почта</div>
      </template>

      <!-- Код отправлен — ввод кода на месте формы адреса. -->
      <AuthCodeForm
        v-if="emailSentTo"
        :email="emailSentTo"
        :state="emailCodeState"
        :error="emailCodeError"
        :loading="savingEmail"
        submit-label="Подтвердить адрес"
        hint="После ввода адрес появится в кабинете."
        @submit="submitEmailCode"
        @resend="resendEmailCode"
        @back="emailSentTo = ''"
      />

      <template v-else>
        <UAlert
          :color="emailInvited ? 'primary' : 'neutral'" variant="subtle"
          title="Почта в кабинете не указана"
          description="VK не сообщил нам ваш адрес. Билеты и чеки приходят на почту, которую вы вводите при покупке — но с адресом в кабинете он подставится сам, а прошлые покупки на этот адрес окажутся в списке билетов."
          class="mb-4"
        />

        <form class="space-y-4" @submit.prevent="sendEmailConfirmation">
          <UFormField
            label="Ваш e-mail" :error="emailErrors.email"
            help="Мы отправим на него код из 6 цифр — адрес привяжется к кабинету после его ввода"
          >
            <UInput
              v-model="newEmail" type="email" autocomplete="email" class="w-full"
              placeholder="you@example.com" :highlight="!!emailErrors.email"
            />
          </UFormField>

          <UButton type="submit" color="error" :loading="savingEmail">Получить код</UButton>
        </form>
      </template>
    </UCard>

    <UCard>
      <template #header>
        <div class="font-medium">{{ user?.has_password ? 'Смена пароля' : 'Пароль для входа' }}</div>
      </template>

      <!-- Вход только через VK: пароля ещё нет, а логином при входе по
           паролю служит e-mail — без него задавать его нечему. -->
      <UAlert
        v-if="user && !user.has_password && !user.email"
        color="neutral" variant="subtle"
        title="Вы входите через VK"
        description="Чтобы можно было входить ещё и по паролю, сначала укажите электронную почту — она будет логином."
      />

      <form v-else class="space-y-4" @submit.prevent="savePassword">
        <UAlert
          v-if="user && !user.has_password"
          color="neutral" variant="subtle"
          title="Вы входите через VK"
          :description="`Можно задать пароль и входить по нему — логином будет ${user.email}. Вход через VK при этом сохранится.`"
          class="mb-2"
        />

        <UFormField
          v-if="user?.has_password"
          label="Текущий пароль" required :error="passwordErrors.current_password"
        >
          <PasswordInput
            v-model="currentPassword" autocomplete="current-password"
            class="w-full" :highlight="!!passwordErrors.current_password"
          />
        </UFormField>
        <UFormField
          :label="user?.has_password ? 'Новый пароль' : 'Пароль'" required
          help="Не менее 8 символов" :error="passwordErrors.password"
        >
          <PasswordInput
            v-model="password" autocomplete="new-password"
            class="w-full" :highlight="!!passwordErrors.password"
          />
        </UFormField>
        <UFormField :label="user?.has_password ? 'Повторите новый пароль' : 'Повторите пароль'" required>
          <PasswordInput
            v-model="passwordRepeat" autocomplete="new-password"
            class="w-full"
          />
        </UFormField>

        <UButton type="submit" color="error" :loading="savingPassword">
          {{ user?.has_password ? 'Изменить пароль' : 'Задать пароль' }}
        </UButton>
      </form>
    </UCard>
  </UContainer>
</template>
