<script setup lang="ts">
/**
 * Виджет Yandex SmartCaptcha — замена самописной картинке legacy
 * (avtovokzal57/captcha.php: четыре цифры шрифтом GD, ответ в сессии).
 * Российский сервис: данные посетителей не уходят за рубеж, в отличие от
 * reCAPTCHA/hCaptcha, которые вдобавок нестабильно грузятся у покупателей.
 *
 * Наружу отдаём одноразовый токен — его проверяет backend по серверному
 * ключу (App\Services\SmartCaptcha). Токен живёт 5 минут и сгорает после
 * первой проверки, поэтому после неудачной отправки формы виджет надо
 * сбрасывать — для этого у компонента есть `reset()`.
 */
const props = defineProps<{
  /** Клиентский ключ (ysc1_…). Без него виджет не показываем. */
  sitekey: string
}>()

const token = defineModel<string>({ default: '' })

const container = ref<HTMLElement | null>(null)
const failed = ref(false)
let widgetId: number | undefined

const SCRIPT_URL = 'https://smartcaptcha.yandexcloud.net/captcha.js'

/** Скрипт грузим один раз на всю страницу, даже если виджетов несколько. */
function loadScript(): Promise<void> {
  const w = window as any

  if (w.smartCaptcha) return Promise.resolve()

  const existing = document.querySelector<HTMLScriptElement>(`script[src^="${SCRIPT_URL}"]`)
  if (existing) {
    return new Promise((resolve, reject) => {
      existing.addEventListener('load', () => resolve())
      existing.addEventListener('error', () => reject(new Error('captcha script')))
    })
  }

  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SCRIPT_URL
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('captcha script'))
    document.head.appendChild(script)
  })
}

function render() {
  const w = window as any
  if (!w.smartCaptcha || !container.value) return

  widgetId = w.smartCaptcha.render(container.value, {
    sitekey: props.sitekey,
    hl: 'ru',
    callback: (value: string) => {
      token.value = value
    },
  })
}

/** Сброс после неудачной отправки: старый токен уже недействителен. */
function reset() {
  const w = window as any
  token.value = ''
  if (w.smartCaptcha && widgetId !== undefined) {
    w.smartCaptcha.reset(widgetId)
  }
}

defineExpose({ reset })

onMounted(async () => {
  try {
    await loadScript()
    render()
  }
  catch {
    // Капча не загрузилась (сеть, блокировщик). Форму не запираем:
    // backend в этом случае решает по captcha.fail_open, а спам ловят
    // словарь и ограничение частоты.
    failed.value = true
  }
})
</script>

<template>
  <div>
    <div ref="container" />
    <p v-if="failed" class="text-sm text-muted">
      Проверка «я не робот» не загрузилась. Сообщение всё равно можно отправить.
    </p>
  </div>
</template>
