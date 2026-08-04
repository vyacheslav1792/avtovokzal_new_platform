<script setup lang="ts">
const api = useApi()
const route = useRoute()
const { register, isLoggedIn } = useAuth()
const { data: providers } = await useAuthProviders()

useSeoMeta({
  title: 'Регистрация в личном кабинете',
  description: 'Регистрация покупателя билетов Орловского автовокзала: история поездок, печать и возврат билетов.',
})

const inviteToken = String(route.query.token ?? '')

// Регистрация может быть начата с формы покупки — тогда возвращаем на рейс,
// а не в кабинет.
const redirect = computed(() => String(route.query.redirect ?? '/lk'))

const email = ref(String(route.query.email ?? ''))
const phone = ref('')
const password = ref('')
const passwordRepeat = ref('')
const agree = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string>>({})
// Приглашение подтверждает почту письмом — менять адрес в этом случае нельзя.
const emailLocked = ref(false)

watchEffect(() => {
  if (isLoggedIn.value) navigateTo(redirect.value)
})

// Ссылка-приглашение из письма после первой покупки: подставляем e-mail.
if (inviteToken) {
  const { data } = await useAsyncData('invite', () =>
    api<{ success: boolean, data: { email: string } }>('/auth/invite', {
      method: 'POST',
      body: { token: inviteToken },
    }).catch(() => null))

  if (data.value?.data.email) {
    email.value = data.value.data.email
    emailLocked.value = true
  }
}

async function submit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (password.value.trim() !== passwordRepeat.value.trim()) {
    fieldErrors.value.password = 'Пароль и подтверждение должны совпадать'
    return
  }

  loading.value = true
  try {
    await register({
      email: email.value,
      phone: phone.value,
      password: password.value,
      agree_pd: agree.value,
      ...(inviteToken ? { invite_token: inviteToken } : {}),
    })
    await navigateTo(redirect.value)
  }
  catch (e: any) {
    fieldErrors.value = apiFieldErrors(e)
    if (!Object.keys(fieldErrors.value).length) {
      errorMessage.value = apiErrorMessage(e, 'Не удалось зарегистрироваться, попробуйте позже')
    }
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UContainer class="py-10 max-w-4xl">
    <h1 class="text-3xl font-light mb-6">Регистрация</h1>

    <div class="grid gap-8 md:grid-cols-2">
      <div>
        <UAlert
          v-if="emailLocked"
          color="success"
          variant="subtle"
          title="Приглашение подтверждено"
          :description="`Кабинет будет создан для ${email}: все покупки на этот адрес окажутся внутри.`"
          class="mb-4"
        />
        <UAlert v-if="errorMessage" color="error" variant="subtle" :title="errorMessage" class="mb-4" />

        <UCard>
          <form class="space-y-4" @submit.prevent="submit">
            <UFormField label="Электронная почта" required :error="fieldErrors.email">
              <UInput
                v-model="email" type="email" autocomplete="email" size="lg" class="w-full"
                :disabled="emailLocked" :highlight="!!fieldErrors.email"
              />
            </UFormField>

            <UFormField
              label="Номер телефона" required :error="fieldErrors.phone"
              help="Понадобится перевозчику, если рейс изменится. Подставляется при покупке."
            >
              <UInput
                v-model="phone" placeholder="+7 910 000-00-00" size="lg" class="w-full"
                autocomplete="tel" :highlight="!!fieldErrors.phone"
              />
            </UFormField>

            <UFormField label="Пароль" required :error="fieldErrors.password" help="Не менее 8 символов">
              <UInput
                v-model="password" type="password" autocomplete="new-password"
                size="lg" class="w-full" :highlight="!!fieldErrors.password"
              />
            </UFormField>

            <UFormField label="Повторите пароль" required>
              <UInput
                v-model="passwordRepeat" type="password" autocomplete="new-password"
                size="lg" class="w-full"
              />
            </UFormField>

            <UFormField :error="fieldErrors.agree_pd">
              <UCheckbox
                v-model="agree"
                label="Я даю согласие на обработку моих персональных данных и данных доверенных лиц"
              />
            </UFormField>

            <div class="flex items-center gap-3 pt-2">
              <UButton type="submit" color="error" size="lg" :loading="loading">
                Зарегистрироваться
              </UButton>
              <UButton
                :to="{ path: '/login', query: { redirect } }"
                variant="ghost" color="neutral"
              >
                Войти
              </UButton>
            </div>
          </form>

          <template v-if="providers?.vk" #footer>
            <div class="flex items-center gap-3 mb-4 text-sm text-muted">
              <span class="h-px flex-1 bg-default" />
              или
              <span class="h-px flex-1 bg-default" />
            </div>
            <VkLoginButton :redirect="redirect" label="Зарегистрироваться через VK" />
            <p class="text-xs text-muted mt-3">
              Пароль придумывать не нужно. Если VK не сообщит нам электронную почту,
              её можно будет указать в профиле — билеты приходят на адрес,
              указанный при покупке.
            </p>
          </template>
        </UCard>
      </div>

      <div>
        <h2 class="text-xl font-medium mb-3">Преимущества личного кабинета</h2>
        <ul class="space-y-3 text-sm">
          <li class="flex gap-2">
            <UIcon name="i-lucide-history" class="size-5 text-primary shrink-0" />
            История заказов: информация о ваших поездках всегда под рукой
          </li>
          <li class="flex gap-2">
            <UIcon name="i-lucide-printer" class="size-5 text-primary shrink-0" />
            Печать билета и скачивание PDF в один клик
          </li>
          <li class="flex gap-2">
            <UIcon name="i-lucide-undo-2" class="size-5 text-primary shrink-0" />
            Простой возврат билетов — без ввода кода с билета
          </li>
          <li class="flex gap-2">
            <UIcon name="i-lucide-zap" class="size-5 text-primary shrink-0" />
            Быстрая покупка: почта и телефон подставляются автоматически
          </li>
        </ul>
      </div>
    </div>
  </UContainer>
</template>
