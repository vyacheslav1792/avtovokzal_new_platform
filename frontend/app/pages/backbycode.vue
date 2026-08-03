<script setup lang="ts">
const api = useApi()

useSeoMeta({
  title: 'Возврат билета по коду для возврата',
  description: 'Быстрый возврат билета по коду без входа в личный кабинет.',
  ogTitle: 'Возврат билета по коду для возврата',
  ogDescription: 'Быстрый возврат билета по коду без входа в личный кабинет.',
})

interface RefundPreview {
  from_name: string
  to_name: string
  trip_name: string
  date_start: string
  time_start: string
  place: string | number
  penalty_percent: number
  amount: number
  confirm_token: string
  confirm_ttl: number
}

interface RefundResult {
  from_name: string
  to_name: string
  trip_name: string
  date_start: string
  time_start: string
  amount: number
  bank_error: string | null
}

const step = ref<'form' | 'preview' | 'done'>('form')
// Код можно передать ссылкой с печатной формы билета: /backbycode?code=…
const code = ref(String(useRoute().query.code ?? ''))
const loading = ref(false)
const errorMessage = ref('')
const preview = ref<RefundPreview | null>(null)
const result = ref<RefundResult | null>(null)

function formatDate(iso: string) {
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}

function extractError(e: any): string {
  return e?.data?.errors?.code?.[0]
    ?? e?.data?.error
    ?? e?.data?.message
    ?? 'Не удалось выполнить операцию, попробуйте позже'
}

async function requestPreview() {
  errorMessage.value = ''
  if (!code.value.trim()) {
    errorMessage.value = 'Введите код для возврата, который напечатан на билете'
    return
  }
  loading.value = true
  try {
    const resp = await api<{ success: boolean, data: RefundPreview }>('/refunds/preview', {
      method: 'POST',
      body: { code: code.value.trim() },
    })
    preview.value = resp.data
    step.value = 'preview'
  }
  catch (e: any) {
    errorMessage.value = extractError(e)
  }
  finally {
    loading.value = false
  }
}

async function confirmRefund() {
  if (!preview.value) return
  errorMessage.value = ''
  loading.value = true
  try {
    const resp = await api<{ success: boolean, data: RefundResult }>('/refunds/confirm', {
      method: 'POST',
      body: { confirm_token: preview.value.confirm_token },
    })
    result.value = resp.data
    step.value = 'done'
  }
  catch (e: any) {
    errorMessage.value = extractError(e)
    step.value = 'form'
  }
  finally {
    loading.value = false
    preview.value = null
  }
}

function reset() {
  step.value = 'form'
  code.value = ''
  errorMessage.value = ''
  preview.value = null
  result.value = null
}
</script>

<template>
  <UContainer class="py-10 max-w-3xl">
    <h1 class="text-3xl font-light mb-6">Возврат билетов</h1>

    <div class="space-y-4 text-justify leading-relaxed">
      <p>
        Возврат билетов осуществляется не позднее чем за 30 мин до отправления автобуса через
        <NuxtLink to="/lk" title="Переход в личный кабинет" class="text-primary underline underline-offset-2">личный кабинет сайта</NuxtLink>,
        а так же по коду для возврата. Код для возврата можно посмотреть на билете.
      </p>
      <p>
        Возврат билетов позднее чем за 30 минут до отправления автобуса, а так же после отправления
        осуществляется по заявлению на автовокзале отправителе.
      </p>
      <p>Деньги на карту зачисляются банком с задержкой 1-7 дней.</p>
    </div>

    <h2 class="text-xl font-medium mt-8 mb-4">Возврат по коду для возврата:</h2>

    <UAlert
      v-if="errorMessage"
      color="error"
      variant="subtle"
      :title="errorMessage"
      class="mb-4"
    />

    <!-- Шаг 1: ввод кода с билета -->
    <UCard v-if="step === 'form'">
      <form class="space-y-4" @submit.prevent="requestPreview">
        <UFormField
          label="Код для возврата"
          help="Введите код для возврата, который напечатан на билете"
        >
          <UInput
            v-model="code"
            size="lg"
            class="w-full max-w-md font-mono"
            placeholder="Например: 400000252572631309900065"
            autocomplete="off"
          />
        </UFormField>
        <UButton type="submit" color="error" size="lg" :loading="loading">
          Вернуть билет
        </UButton>
      </form>
    </UCard>

    <!-- Шаг 2: условия возврата -->
    <UCard v-else-if="step === 'preview' && preview">
      <div class="space-y-3">
        <p>
          Билет от <b>{{ preview.from_name }} до {{ preview.to_name }}</b>
          на рейс: {{ preview.trip_name }}, место {{ preview.place }}
        </p>
        <p>Отправление: <b>{{ formatDate(preview.date_start) }} {{ preview.time_start }}</b></p>
        <p>будут возвращены на следующих условиях:</p>
        <p>Процент удержаний: <b>{{ preview.penalty_percent }}%</b></p>
        <p>Возвращаемая сумма: <b>{{ preview.amount.toFixed(2) }}</b> руб.</p>
        <UAlert
          color="warning"
          variant="subtle"
          title="Денежные средства возвращаются банком в срок от 3 до 7 дней, в некоторых случаях банк эмитент может увеличить этот срок до 30 дней."
        />
        <p>Для подтверждения операции кликните выполнить возврат.</p>
        <div class="flex gap-3 pt-2">
          <UButton color="error" size="lg" :loading="loading" @click="confirmRefund">
            Выполнить возврат
          </UButton>
          <UButton color="neutral" variant="outline" size="lg" :disabled="loading" @click="reset">
            Не возвращать
          </UButton>
        </div>
      </div>
    </UCard>

    <!-- Шаг 3: результат -->
    <UCard v-else-if="step === 'done' && result">
      <div class="space-y-3">
        <UAlert
          color="success"
          variant="subtle"
          :title="`Билеты от ${result.from_name} до ${result.to_name} на рейс ${result.trip_name} возвращены успешно`"
          :description="`Отправление ${formatDate(result.date_start)} ${result.time_start}`"
        />
        <p>Сумма: <b>{{ result.amount.toFixed(2) }}</b> руб.</p>
        <p><b>Внимание!</b> Деньги на карту зачисляются банком с задержкой 1-7 дней.</p>
        <UAlert
          v-if="result.bank_error"
          color="warning"
          variant="subtle"
          :title="result.bank_error"
        />
        <UButton color="error" size="lg" @click="reset">
          Вернуть ещё один билет
        </UButton>
      </div>
    </UCard>
  </UContainer>
</template>
