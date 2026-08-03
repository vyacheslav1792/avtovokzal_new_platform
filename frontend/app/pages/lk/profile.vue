<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

useSeoMeta({ title: 'Профиль — личный кабинет' })

const { user, updateProfile, changePassword, logout } = useAuth()
const toast = useToast()

const phone = ref(formatPhone(user.value?.phone))
const name = ref(user.value?.name ?? '')
const savingProfile = ref(false)
const profileErrors = ref<Record<string, string>>({})

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
      <h1 class="text-3xl font-light">Профиль</h1>
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
        <UFormField label="Электронная почта" help="E-mail — логин кабинета, на него приходят билеты">
          <UInput :model-value="user?.email" size="lg" class="w-full" disabled />
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

    <UCard>
      <template #header>
        <div class="font-medium">Смена пароля</div>
      </template>

      <form class="space-y-4" @submit.prevent="savePassword">
        <UFormField label="Текущий пароль" required :error="passwordErrors.current_password">
          <UInput
            v-model="currentPassword" type="password" autocomplete="current-password"
            size="lg" class="w-full" :highlight="!!passwordErrors.current_password"
          />
        </UFormField>
        <UFormField label="Новый пароль" required help="Не менее 8 символов" :error="passwordErrors.password">
          <UInput
            v-model="password" type="password" autocomplete="new-password"
            size="lg" class="w-full" :highlight="!!passwordErrors.password"
          />
        </UFormField>
        <UFormField label="Повторите новый пароль" required>
          <UInput
            v-model="passwordRepeat" type="password" autocomplete="new-password"
            size="lg" class="w-full"
          />
        </UFormField>

        <UButton type="submit" color="error" :loading="savingPassword">Изменить пароль</UButton>
      </form>
    </UCard>
  </UContainer>
</template>
