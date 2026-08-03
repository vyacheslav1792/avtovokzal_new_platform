<script setup>
// Админский возврат билета — перенос legacy ais/back_bilet.php.
// Работает и как страница (/back-ticket?order_id=…&serial=…&number=…),
// и в оверлее поверх отчёта «Продажи сайта» (props от ReportView).
// Защитный код 123321 из legacy сознательно не перенесён (будет авторизация).
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps({
  orderId: { type: String, default: '' },
  serial: { type: String, default: '' },
  number: { type: String, default: '' },
  overlay: { type: Boolean, default: false }, // не трогаем title вкладки
})

const route = useRoute()
const orderId = computed(() => props.orderId || route.query.order_id || '')
const serial = computed(() => props.serial || route.query.serial || '')
const number = computed(() => props.number || route.query.number || '')

const info = ref(null)       // ответ inspect
const loading = ref(false)
const error = ref('')
const done = ref(null)       // результат confirm/repair
const percent = ref(-1)      // -1 = «Не выбран» (как в legacy)
const confirming = ref(false)

const SITE_API = '/site-api/v1/admin/refunds'

async function req(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  let data = null
  try { data = await r.json() } catch (e) { /* не JSON */ }
  if (!r.ok || !data || data.success === false) {
    // Laravel validation: {message, errors: {поле: [тексты]}}
    const msg = data && data.errors
      ? Object.values(data.errors).flat().join(' ')
      : (data && (data.message || data.error)) || `Ошибка запроса (HTTP ${r.status})`
    throw new Error(msg)
  }
  return data.data
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    info.value = await req(`${SITE_API}/inspect`, {
      order_id: orderId.value,
      serial: serial.value || null,
      number: number.value || null,
    })
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function confirmRefund() {
  // Legacy: PROC<0 → «Не выбран процент удержаний!»
  if (percent.value < 0) {
    error.value = 'Не выбран процент удержаний!'
    return
  }
  confirming.value = true
  error.value = ''
  try {
    done.value = await req(`${SITE_API}/confirm`, {
      order_id: orderId.value,
      serial: serial.value,
      number: number.value,
      percent: percent.value,
    })
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

async function repairMoney() {
  confirming.value = true
  error.value = ''
  try {
    done.value = await req(`${SITE_API}/repair`, {
      order_id: orderId.value,
      serial: serial.value,
      number: number.value,
    })
    done.value.repaired = true
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

function money(v) {
  const n = Number(v)
  return isFinite(n) ? n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v
}

function dmy(iso) {
  if (!iso) return ''
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso
}

onMounted(() => {
  if (!props.overlay) document.title = 'Возврат билета · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Возврат билета (админ)</h1>

    <div v-if="loading" class="muted loading">Загрузка данных заказа…</div>
    <div v-if="error" class="alert">{{ error }}</div>

    <template v-if="info">
      <!-- Сверка со шлюзом (legacy: «Информация об оплате из сбербанка») -->
      <section class="bt-block">
        <h3>Информация об оплате из сбербанка</h3>
        <div v-if="info.bank.error" class="alert warn">{{ info.bank.error }}</div>
        <p v-else>
          {{ info.order.sber_order_id }} Статус заказа: {{ info.bank.order_status }}
          Расшифровка: <b>{{ info.bank.status_text }}</b> {{ info.bank.action_code_description }}
          Платёж: {{ money(info.bank.amount) }} руб.
          Возврат: {{ money(info.bank.refunded) }} руб.
        </p>
      </section>

      <!-- Заказ и пассажиры (legacy показывал email + пароль ЛК) -->
      <section class="bt-block">
        <h3>Заказ</h3>
        <p>
          <b>{{ info.order.from_name }} — {{ info.order.to_name }}</b>,
          рейс {{ info.order.trip_name }},
          отправление {{ dmy(info.order.date_start) }} {{ info.order.time_start }}<br />
          Email: <b>{{ info.order.email }}</b>
          <template v-if="info.order.lk_password"> Пароль от личного кабинета: <b>{{ info.order.lk_password }}</b></template>
          <template v-if="info.order.phone"> Телефон: {{ info.order.phone }}</template>
        </p>
        <h3>Информация о пассажирах</h3>
        <ul class="bt-passengers">
          <li v-for="(p, i) in info.passengers" :key="i">
            {{ p.name }} Гражданство: {{ p.citizenship }} Дата рождения: {{ dmy(p.birth_date) }} Документ: {{ p.document }}
          </li>
        </ul>
      </section>

      <!-- Результат возврата / починки -->
      <section v-if="done" class="bt-block bt-done">
        <template v-if="done.repaired">
          <p><b>Возврат денег по банку выполнен.</b> Сумма: {{ money(done.amount) }} руб.</p>
        </template>
        <template v-else>
          <p>
            Билеты от <b>{{ info.order.from_name }} до {{ info.order.to_name }}</b>
            на рейс {{ done.trip_name }} возвращены успешно
          </p>
          <p>Сумма: {{ money(done.amount) }} руб.</p>
        </template>
        <p><b style="font-size: 16pt">Внимание!</b> Деньги на карту зачисляются банком с задержкой 1-7 дней.</p>
        <p v-if="done.bank_error" class="alert">{{ done.bank_error }}</p>
      </section>

      <!-- Состояние билета -->
      <template v-else>
        <section v-if="info.state === 'no_ticket'" class="bt-block alert warn">
          {{ info.message }}
        </section>

        <section v-else-if="info.state === 'refundable'" class="bt-block">
          <h3>Возврат билета</h3>
          <p>
            Билет Серия {{ info.ticket.serial }} Номер {{ info.ticket.number }}<br />
            Пассажир: {{ info.ticket.passenger }}<br />
            Место: {{ info.ticket.place }}.<br />
            Цена: {{ money(info.ticket.price) }} руб.<br />
            Багажных мест: {{ info.ticket.baggage }} шт. по {{ money(info.ticket.price_bag) }} руб<br />
            на рейс <b>{{ info.trip_name }}</b><br />
            от <b>{{ info.order.from_name }}</b> до <b>{{ info.order.to_name }}</b>
          </p>
          <p>будет возвращен на следующих условиях</p>
          <p>
            Процент удержаний:
            <select v-model.number="percent">
              <option :value="-1">Не выбран</option>
              <option v-for="v in info.variants" :key="v.percent" :value="v.percent">
                {{ v.percent }}% — к возврату {{ money(v.amount) }} руб.
              </option>
            </select>
            Рекомендуется: <b>{{ info.recommended_percent }} %</b>
          </p>
          <p>Для подтверждения операции кликните выполнить возврат.</p>
          <button class="btn primary" :disabled="confirming" @click="confirmRefund">
            {{ confirming ? 'Выполняется…' : 'Выполнить возврат' }}
          </button>
        </section>

        <section v-else-if="info.state === 'repair_money'" class="bt-block">
          <h3>Проверка возврата</h3>
          <p class="alert">{{ info.message }}</p>
          <p>
            Возврат от {{ info.refund.refunded_at }} на {{ money(info.refund.amount) }} руб.
            (удержано {{ info.refund.penalty_percent }}%).
            <template v-if="info.refund.bank_error"><br />Ошибка банка: {{ info.refund.bank_error }}</template>
          </p>
          <button class="btn primary" :disabled="confirming" @click="repairMoney">
            {{ confirming ? 'Выполняется…' : 'Выполнить возврат по банку' }}
          </button>
        </section>

        <section v-else-if="info.state === 'no_receipt'" class="bt-block">
          <h3>Проверка возврата</h3>
          <p class="alert warn">{{ info.message }}</p>
          <p>
            Возврат от {{ info.refund.refunded_at }} на {{ money(info.refund.amount) }} руб.,
            деньги возвращены {{ info.refund.money_refunded_at }}.
          </p>
        </section>

        <section v-else-if="info.state === 'already_refunded'" class="bt-block">
          <h3>Проверка возврата</h3>
          <p>{{ info.message }}</p>
          <p>
            Возврат от {{ info.refund.refunded_at }} на {{ money(info.refund.amount) }} руб.
            (удержано {{ info.refund.penalty_percent }}%), ФП чека возврата: {{ info.refund.fiscal_sign }}.
          </p>
        </section>
      </template>
    </template>
  </div>
</template>

<style scoped>
.bt-block { margin: 14px 0; padding: 12px 16px; background: #fff; border: 1px solid var(--line, #d8dee8); border-radius: 8px; }
.bt-block h3 { margin: 0 0 8px; }
.bt-passengers { list-style: none; padding-left: 0; }
.bt-passengers li { padding: 2px 0; }
.bt-done { background: #f0fff0; }
</style>
