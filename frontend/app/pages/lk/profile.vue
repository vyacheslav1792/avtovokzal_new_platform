<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

useSeoMeta({ title: 'Профиль — личный кабинет' })

const { user, updateProfile, changePassword, requestEmail, logout } = useAuth()
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
 * до регистрации, поэтому владение подтверждается ссылкой из письма.
 */
const newEmail = ref('')
const savingEmail = ref(false)
const emailErrors = ref<Record<string, string>>({})
const emailSentTo = ref('')
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
  emailSentTo.value = ''
  savingEmail.value = true
  try {
    const message = await requestEmail(newEmail.value)
    emailSentTo.value = newEmail.value.trim()
    toast.add({ title: message, color: 'success' })
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
          <UInput :model-value="user.email" size="lg" class="w-full" disabled />
        </UFormField>

        <UFormField
          label="Номер телефона" required :error="profileErrors.phone"
          help="Подставляется при покупке; изменить его можно и в самой форме покупки"
        >
          <UInput
            v-model="phone" size="lg" class="w-full" placeholder="+7 910 000-00-00"
            :highlight="!!profileErrors.phone"
          />
        </UFormField>

        <UFormField label="Как к вам обращаться" :error="profileErrors.name">
          <UInput v-model="name" size="lg" class="w-full" placeholder="Необязательно" />
        </UFormField>

        <UButton type="submit" color="error" :loading="savingProfile">Сохранить</UButton>
      </form>
    </UCard>

    <!-- Кабинет из VK без почты: предлагаем добавить, но не требуем. -->
    <UCard v-if="user && !user.email">
      <template #header>
        <div class="font-medium">Электронная почта</div>
      </template>

      <UAlert
        v-if="emailSentTo"
        color="success" variant="subtle"
        title="Письмо отправлено"
        :description="`Откройте ссылку из письма на ${emailSentTo} — после этого адрес появится в кабинете.`"
        class="mb-4"
      />
      <UAlert
        v-else
        :color="emailInvited ? 'primary' : 'neutral'" variant="subtle"
        title="Почта в кабинете не указана"
        description="VK не сообщил нам ваш адрес. Билеты и чеки приходят на почту, которую вы вводите при покупке — но с адресом в кабинете он подставится сам, а прошлые покупки на этот адрес окажутся в списке билетов."
        class="mb-4"
      />

      <form class="space-y-4" @submit.prevent="sendEmailConfirmation">
        <UFormField
          label="Ваш e-mail" :error="emailErrors.email"
          help="Мы отправим на него письмо со ссылкой — адрес привяжется к кабинету после перехода по ней"
        >
          <UInput
            v-model="newEmail" type="email" autocomplete="email" size="lg" class="w-full"
            placeholder="you@example.com" :highlight="!!emailErrors.email"
          />
        </UFormField>

        <UButton type="submit" color="error" :loading="savingEmail">Отправить подтверждение</UButton>
      </form>
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
          <UInput
            v-model="currentPassword" type="password" autocomplete="current-password"
            size="lg" class="w-full" :highlight="!!passwordErrors.current_password"
          />
        </UFormField>
        <UFormField
          :label="user?.has_password ? 'Новый пароль' : 'Пароль'" required
          help="Не менее 8 символов" :error="passwordErrors.password"
        >
          <UInput
            v-model="password" type="password" autocomplete="new-password"
            size="lg" class="w-full" :highlight="!!passwordErrors.password"
          />
        </UFormField>
        <UFormField :label="user?.has_password ? 'Повторите новый пароль' : 'Повторите пароль'" required>
          <UInput
            v-model="passwordRepeat" type="password" autocomplete="new-password"
            size="lg" class="w-full"
          />
        </UFormField>

        <UButton type="submit" color="error" :loading="savingPassword">
          {{ user?.has_password ? 'Изменить пароль' : 'Задать пароль' }}
        </UButton>
      </form>
    </UCard>
  </UContainer>
</template>
