<script setup lang="ts">
/**
 * Обратная связь (перенос legacy модалки «Написать сообщение» из подвала
 * avtovokzal57/index.php + обработчика url/contact.php).
 *
 * Отличия от legacy — по решению заказчика форму делаем заново:
 *  — отдельная страница вместо модалки в подвале (адресом можно поделиться,
 *    на телефоне форма не ужимается в узкое окно);
 *  — тема обращения (в legacy все письма шли с одинаковым заголовком);
 *  — телефон (необязательно) — по нему быстрее перезвонить;
 *  — SmartCaptcha вместо картинки с четырьмя цифрами;
 *  — сообщение сохраняется в базе и разбирается в АИС, а не живёт только
 *    в почте поддержки.
 */
const api = useApi()
const { user, isLoggedIn } = useAuth()
const config = useRuntimeConfig()

useSeoMeta({
  title: 'Напишите нам — обратная связь',
  description: 'Задать вопрос или оставить обращение в АО «Орелавтотранс»: '
    + 'билеты, возврат, расписание, работа автовокзалов Орловской области.',
})

// Темы, признак капчи и подписанная метка времени открытия формы.
const { data: meta } = await useAsyncData('feedback-form', () =>
  api<{ data: FeedbackFormMeta }>('/feedback/form').then(r => r.data))

interface FeedbackFormMeta {
  topics: { value: string, label: string }[]
  captcha_enabled: boolean
  form_token: string
  max_body_length: number
}

const form = reactive({
  topic: '',
  name: '',
  email: '',
  phone: '',
  body: '',
  agree_pd: false,
  captcha_token: '',
  // Honeypot: поле спрятано от человека, заполняют его только боты.
  company: '',
})

// У авторизованного покупателя подставляем данные профиля — он не должен
// вводить их заново, как и на форме покупки.
watchEffect(() => {
  if (isLoggedIn.value && user.value) {
    form.name ||= user.value.name ?? ''
    form.email ||= user.value.email ?? ''
    form.phone ||= user.value.phone ?? ''
  }
})

const captcha = useTemplateRef('captcha')
const captchaKey = computed(() => config.public.captchaKey)
const captchaVisible = computed(() => Boolean(meta.value?.captcha_enabled && captchaKey.value))

const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string>>({})
const sent = ref(false)
const sentMessage = ref('')

const bodyLeft = computed(() => (meta.value?.max_body_length ?? 5000) - form.body.length)

async function submit() {
  errorMessage.value = ''
  fieldErrors.value = {}
  loading.value = true

  try {
    const response = await api<{ data: { message: string } }>('/feedback', {
      method: 'POST',
      body: { ...form, form_token: meta.value?.form_token },
    })
    sentMessage.value = response.data.message
    sent.value = true
  }
  catch (e: any) {
    errorMessage.value = apiErrorMessage(e, 'Не удалось отправить сообщение, попробуйте позже')
    fieldErrors.value = apiFieldErrors(e)
    // Токен капчи одноразовый: после отказа нужен новый.
    captcha.value?.reset()
    form.captcha_token = ''
  }
  finally {
    loading.value = false
  }
}

/** Написать ещё одно обращение: форма и метка времени нужны свежие. */
async function again() {
  sent.value = false
  form.topic = ''
  form.body = ''
  form.agree_pd = false
  form.captcha_token = ''
  await refreshNuxtData('feedback-form')
}
</script>

<template>
  <UContainer class="py-8 sm:py-10 max-w-3xl">
    <h1 class="text-3xl font-light mb-2">
      Напишите нам
    </h1>
    <p class="text-muted mb-6">
      Ответим на указанный e-mail. Обращения обрабатываются в рабочие дни с 8 до 17 часов.
    </p>

    <template v-if="!sent">
      <!-- В legacy это была первая строка модалки — просьба заглянуть в FAQ. -->
      <UAlert
        icon="i-lucide-circle-help"
        color="info"
        variant="subtle"
        class="mb-6"
      >
        <template #description>
          Прежде чем писать, загляните в
          <NuxtLink to="/faq" class="underline font-medium">
            ответы на частые вопросы
          </NuxtLink>
          — скорее всего, ответ уже там. Вопросы по конкретной поездке
          решаются быстрее по телефону справочной:
          <a href="tel:+74862721111" class="underline whitespace-nowrap">8 (4862) 72-11-11</a>.
        </template>
      </UAlert>

      <UAlert
        v-if="errorMessage"
        color="error"
        variant="subtle"
        :title="errorMessage"
        class="mb-4"
      />

      <UCard>
        <form class="space-y-5" @submit.prevent="submit">
          <UFormField label="Тема обращения" :error="fieldErrors.topic">
            <USelect
              v-model="form.topic"
              :items="meta?.topics ?? []"
              value-key="value"
              placeholder="Выберите тему"
              size="lg"
              class="w-full"
            />
          </UFormField>

          <div class="grid gap-5 sm:grid-cols-2">
            <UFormField label="Ваше имя" required :error="fieldErrors.name">
              <UInput
                v-model="form.name"
                placeholder="Например, Андрей"
                autocomplete="name"
                size="lg"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="E-mail для ответа"
              required
              :error="fieldErrors.email"
              :help="isLoggedIn && user?.email ? 'Адрес вашего личного кабинета' : undefined"
            >
              <UInput
                v-model="form.email"
                type="email"
                placeholder="Например, ivanov@mail.ru"
                autocomplete="email"
                size="lg"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            label="Телефон"
            :error="fieldErrors.phone"
            help="Необязательно — по телефону вопрос решается быстрее"
          >
            <UInput
              v-model="form.phone"
              type="tel"
              placeholder="+7 (900) 000-00-00"
              autocomplete="tel"
              size="lg"
              class="w-full sm:max-w-xs"
            />
          </UFormField>

          <UFormField label="Сообщение" required :error="fieldErrors.body">
            <UTextarea
              v-model="form.body"
              :rows="7"
              :maxlength="meta?.max_body_length"
              placeholder="Опишите вопрос. Если он о конкретной поездке, укажите дату, рейс и номер заказа — так ответ придёт быстрее."
              size="lg"
              class="w-full"
            />
            <template #hint>
              <span class="text-xs text-dimmed">осталось {{ bodyLeft }}</span>
            </template>
          </UFormField>

          <!-- Honeypot: человек этого поля не видит и не заполняет. -->
          <div class="hidden" aria-hidden="true">
            <label>
              Организация
              <input v-model="form.company" type="text" tabindex="-1" autocomplete="off">
            </label>
          </div>

          <UCheckbox
            v-model="form.agree_pd"
            :color="fieldErrors.agree_pd ? 'error' : 'primary'"
          >
            <template #label>
              Я даю согласие на
              <NuxtLink to="/station-sogl-pd" target="_blank" class="underline">
                обработку моих персональных данных и данных доверенных лиц
              </NuxtLink>
            </template>
            <template #description>
              <NuxtLink to="/station-polit-pd" target="_blank" class="underline">
                Политика обработки персональных данных АО «Орелавтотранс»
              </NuxtLink>
            </template>
          </UCheckbox>
          <p v-if="fieldErrors.agree_pd" class="text-sm text-error -mt-3">
            {{ fieldErrors.agree_pd }}
          </p>

          <div v-if="captchaVisible">
            <SmartCaptcha
              ref="captcha"
              v-model="form.captcha_token"
              :sitekey="captchaKey"
            />
            <p v-if="fieldErrors.captcha_token" class="text-sm text-error mt-1">
              {{ fieldErrors.captcha_token }}
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-3 pt-1">
            <UButton type="submit" size="lg" :loading="loading" icon="i-lucide-send">
              Отправить сообщение
            </UButton>
            <UButton to="/contacts" variant="ghost" color="neutral">
              Телефоны и адреса
            </UButton>
          </div>
        </form>
      </UCard>
    </template>

    <div v-else class="space-y-4">
      <UAlert
        icon="i-lucide-check"
        color="success"
        variant="subtle"
        title="Сообщение отправлено"
        :description="sentMessage"
      />
      <div class="flex flex-wrap gap-3">
        <UButton to="/" color="neutral" variant="outline">
          На главную
        </UButton>
        <UButton variant="ghost" color="neutral" @click="again">
          Написать ещё
        </UButton>
      </div>
    </div>
  </UContainer>
</template>
