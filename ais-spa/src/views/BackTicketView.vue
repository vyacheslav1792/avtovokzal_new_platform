<script setup>
// Админский возврат билета — перенос legacy ais/back_bilet.php.
// Работает и как страница (/back-ticket?order_id=…&serial=…&number=…),
// и в оверлее поверх отчёта «Продажи сайта» (props от ReportView).
// Защитный код 123321 из legacy сознательно не перенесён: его заменили
// авторизация сотрудника и личный код подтверждения, который сервер
// спрашивает перед возвратом денег (права back-ticket.refund/repair).
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { can } from '../auth'
import { refundAction } from '../siteApi'

const props = defineProps({
  orderId: { type: String, default: '' },
  serial: { type: String, default: '' },
  number: { type: String, default: '' },
  overlay: { type: Boolean, default: false }, // не трогаем title вкладки
})

const route = useRoute()
const router = useRouter()

/*
 * Выбранный билет можно сменить, не закрывая карточку (список билетов
 * заказа). В режиме оверлея — поверх отчёта — адрес менять нельзя, там
 * карточкой управляют props, поэтому держим выбор у себя.
 */
const overrideSerial = ref('')
const overrideNumber = ref('')

const orderId = computed(() => props.orderId || route.query.order_id || '')
const serial = computed(() => overrideSerial.value || props.serial || route.query.serial || '')
const number = computed(() => overrideNumber.value || props.number || route.query.number || '')

const info = ref(null)       // ответ inspect
const loading = ref(false)
const error = ref('')
const done = ref(null)       // результат confirm/repair
const notice = ref('')       // итог досылки аннулирования на станцию

/*
 * Возврат БЕЗ фискального чека. По 54-ФЗ чек нужен, но бывает, что он уже
 * пробит вручную на кассе — тогда второй не нужен. Пробить его позже можно
 * кнопкой у самой операции.
 */
const withoutReceipt = ref(false)

// Раскрытая техника отказа шлюза (по id операции): кассиру нужны код и
// состояние платежа, а не «Системная ошибка.».
const openedDetails = ref(new Set())

function toggleDetails(id) {
  const next = new Set(openedDetails.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  openedDetails.value = next
}
const percent = ref(-1)      // -1 = «Не выбран» (как в legacy)
const confirming = ref(false)

/*
 * Запросы идут через общий слой (siteApi → http.js), а не своим fetch:
 * возврат денег и починка зависшего возврата помечены в каталоге прав как
 * опасные, сервер отвечает `need_confirm`, и код подтверждения спрашивает
 * окно. Со своим fetch это было ошибкой «Действие требует подтверждения
 * кодом.» — кнопка просто не работала.
 */
const req = refundAction

async function load() {
  loading.value = true
  error.value = ''
  try {
    info.value = await req('inspect', {
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
    done.value = await req('confirm', {
      order_id: orderId.value,
      serial: serial.value,
      number: number.value,
      percent: percent.value,
      without_receipt: withoutReceipt.value,
    })
    // Карточку перезагружаем: после возврата по заказу обычно есть что
    // сделать дальше (багаж, удержание, чек), и закрывать окно незачем.
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/**
 * Возврат ДЕНЕГ при недоступной базе станции: аннулирование билета
 * досылается позже кнопкой. Сервер сам проверит, что станция правда не
 * отвечает, — обойти обычный путь этой кнопкой нельзя.
 */
async function confirmWithoutStation() {
  if (percent.value < 0) {
    error.value = 'Не выбран процент удержаний!'
    return
  }
  const ok = window.confirm(
    'Вернуть деньги, не аннулируя билет на станции?\n\n'
    + 'Покупатель получит деньги сейчас. На станции билет останется проданным, '
    + 'а место — занятым, пока вы не дошлёте аннулирование (появится в разделе '
    + '«Возвраты без станции»).',
  )
  if (!ok) return

  confirming.value = true
  error.value = ''
  try {
    done.value = await req('confirm', {
      order_id: orderId.value,
      serial: serial.value,
      number: number.value,
      percent: percent.value,
      without_station: true,
      without_receipt: withoutReceipt.value,
    })
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/**
 * Отменить платёж по заказу, у которого билетов нет: дооформление не
 * состоялось, а деньги списаны. То же делает автоматика брошенных
 * покупок, но её ждать до часа — кассиру нужна кнопка.
 */
async function cancelPayment() {
  const sum = info.value?.unfinished_payment?.amount
  const ok = window.confirm(
    `Вернуть покупателю ${money(sum)} руб. по неоформленному заказу?\n\n`
    + 'Сначала пробуем отмену платежа — она мягче, деньги возвращаются без отдельной '
    + 'операции по карте. Если платёж уже списан или операционный день шлюза закрыт, '
    + 'шлюз проведёт полный возврат: он идёт дольше и будет видён покупателю отдельной '
    + 'строкой в выписке. Чем закончилось, будет написано в ответе. Бронь мест снимется, '
    + 'заказ закроется.',
  )
  if (!ok) return

  confirming.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await req('cancel-payment', { order_id: orderId.value })
    notice.value = result.message
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/**
 * Пробить фискальный чек ПОКУПКИ вручную (он один на заказ). Чек продажи
 * мог не пробиться при оформлении: ККТ без связи, закрытая смена, не
 * запущен воркер очереди чеков.
 */
async function registerSaleReceipt() {
  confirming.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await req('sale-receipt', { order_id: orderId.value })
    notice.value = result.message
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/**
 * Пробить фискальный чек по операции вручную: очередь чеков могла не
 * отработать или ККТ отказала (закрытая смена, нет связи). Операция
 * идемпотентна — второй чек по той же операции ККТ не пробьёт.
 */
async function registerReceipt(refund) {
  confirming.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await req('receipt', { order_id: orderId.value, refund_id: refund.id })
    notice.value = result.message
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/** Дослать аннулирование на станцию (станция снова на связи). */
async function annulAtStation(refundId) {
  confirming.value = true
  error.value = ''
  try {
    const result = await req('annul', { order_id: orderId.value, refund_id: refundId })
    notice.value = result.message
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/*
 * Возврат ТОЛЬКО багажа: билет остаётся действующим, на станции
 * аннулируются отдельные багажные бланки. Мест выбирается столько,
 * сколько реально осталось невозвращённых квитанций.
 */
const baggageCount = ref(1)
const baggagePercent = ref(-1)

async function refundBaggage() {
  if (baggagePercent.value < 0) {
    error.value = 'Не выбран процент удержаний по багажу!'
    return
  }
  if (!window.confirm(`Вернуть ${baggageCount.value} мест(а) багажа? Билет останется действующим.`)) return

  confirming.value = true
  error.value = ''
  try {
    done.value = await req('baggage', {
      order_id: orderId.value,
      serial: serial.value,
      number: number.value,
      count: baggageCount.value,
      percent: baggagePercent.value,
      without_receipt: withoutReceipt.value,
    })
    done.value.baggage = true
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/*
 * Возврат удержания: станция о нём не знает — ведомость удержание уже
 * посчитала. Мы лишь отдаём покупателю удержанные деньги.
 */
/*
 * Удержание возвращается ЧАСТЯМИ: кассиру нередко надо отдать покупателю
 * лишь часть, а остальное — позже. Поле суммы по умолчанию равно остатку,
 * сервер сверяет его сам (больше остатка не отдаст).
 */
const penaltyAmount = reactive({})

function penaltyInput(refund) {
  if (penaltyAmount[refund.id] === undefined) {
    penaltyAmount[refund.id] = refund.penalty_left
  }

  return penaltyAmount[refund.id]
}

async function refundPenalty(refund) {
  const amount = Number(penaltyAmount[refund.id] ?? refund.penalty_left)

  if (!(amount > 0)) {
    error.value = 'Укажите сумму возврата удержания'
    return
  }
  if (amount > refund.penalty_left) {
    error.value = `Больше остатка вернуть нельзя: осталось ${money(refund.penalty_left)} руб.`
    return
  }
  if (!window.confirm(
    `Вернуть покупателю ${money(amount)} руб. удержания по возврату ${refund.serial} ${refund.number}`
    + ` (удержано ${money(refund.withheld)}, осталось ${money(refund.penalty_left)})?`,
  )) return

  confirming.value = true
  error.value = ''
  try {
    done.value = await req('penalty', {
      order_id: orderId.value,
      refund_id: refund.id,
      amount,
      without_receipt: withoutReceipt.value,
    })
    done.value.penalty = true
    delete penaltyAmount[refund.id]
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/** Сумма к возврату за выбранное число мест при выбранном проценте. */
const baggageAmount = computed(() => {
  const variant = (info.value?.baggage?.variants ?? []).find((v) => v.percent === baggagePercent.value)

  return variant ? variant.amounts[baggageCount.value - 1] : null
})

async function repairMoney() {
  confirming.value = true
  error.value = ''
  try {
    done.value = await req('repair', {
      order_id: orderId.value,
      serial: serial.value,
      number: number.value,
      without_receipt: withoutReceipt.value,
    })
    done.value.repaired = true
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/*
 * Починка возврата денег по КОНКРЕТНОЙ строке из списка возвратов:
 * зависнуть может не только возврат билета, но и возврат удержания или
 * багажа — у них свои обращения к шлюзу, и номера билета у багажной
 * квитанции нет, поэтому строку указываем по id.
 */
async function repairRefund(refund) {
  if (!window.confirm(
    `Повторить возврат ${money(refund.amount)} руб. по банку (${refund.kind_label} ${refund.serial} ${refund.number})?`,
  )) return

  confirming.value = true
  error.value = ''
  try {
    done.value = await req('repair', {
      order_id: orderId.value,
      refund_id: refund.id,
      without_receipt: withoutReceipt.value,
    })
    done.value.repaired = true
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    confirming.value = false
  }
}

/**
 * Открыть другой билет этого же заказа, не закрывая карточку: кассиру
 * почти всегда нужно разобраться со всем заказом, а не с одним бланком.
 * В режиме оверлея (поверх отчёта) адрес не меняем — там карточкой
 * управляют props.
 */
function openTicket(ticket) {
  error.value = ''
  notice.value = ''
  done.value = null

  if (props.overlay) {
    overrideSerial.value = ticket.serial
    overrideNumber.value = ticket.number
    load()

    return
  }

  router.replace({
    path: '/back-ticket',
    query: { order_id: orderId.value, serial: ticket.serial, number: ticket.number },
  })
}

/** Техника отказа шлюза — в человекочитаемый вид, без JSON-мусора. */
function bankDetailsText(d) {
  if (!d) return ''
  const lines = []
  if (d.at) lines.push(`Когда: ${d.at}`)
  if (d.error) {
    lines.push(`Ошибка шлюза: код ${d.error.code ?? '—'} · ${d.error.message ?? ''}`)
    if (d.error.class) lines.push(`Класс исключения: ${d.error.class}`)
  }
  if (d.request) {
    lines.push(`Отправляли: ${d.request.amount} руб. (${d.request.amount_kopecks} коп.), `
      + `вид операции ${d.request.refund_kind}, платёж ${d.request.sber_order_id}`)
  }
  if (d.gateway) {
    if (d.gateway.error) {
      lines.push(`Состояние платежа узнать не удалось: ${d.gateway.error}`)
    } else {
      lines.push(`Платёж в шлюзе: статус ${d.gateway.order_status} (${d.gateway.order_status_text ?? '—'}), `
        + `состояние ${d.gateway.payment_state ?? '—'}`)
      lines.push(`Суммы: платёж ${d.gateway.amount ?? '—'}, возвращено ${d.gateway.refunded ?? '—'}, `
        + `доступно ${d.gateway.deposited ?? '—'}`)
      if (d.gateway.action_code_description) lines.push(`Расшифровка: ${d.gateway.action_code_description}`)
    }
  }
  return lines.join('\n')
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
    <!--
      Сообщения закреплены сверху: карточка заказа длинная, и прокрутив её
      вниз, кассир не видел ни ошибки, ни подтверждения — нажимал ещё раз
      «потому что ничего не произошло». Именно так появились пять записей
      возврата удержания по одному билету.
    -->
    <div v-if="error || notice" class="bt-messages">
      <div v-if="error" class="alert">
        {{ error }}
        <button class="btn small bt-msg-close" type="button" @click="error = ''">✕</button>
      </div>
      <div v-if="notice" class="alert ok">
        {{ notice }}
        <button class="btn small bt-msg-close" type="button" @click="notice = ''">✕</button>
      </div>
    </div>

    <template v-if="info">
      <!--
        Ссылка «Бланк» из отчёта возвратов может вести на БАГАЖНУЮ квитанцию:
        карточка открывает билет, которому она принадлежит, и говорит об этом,
        иначе выглядит как «показали не то, что просили».
      -->
      <div v-if="info.opened_by_baggage" class="alert warn">
        Открыто по багажной квитанции {{ info.opened_by_baggage.serial }}
        {{ info.opened_by_baggage.number }} — ниже карточка её билета
        {{ info.ticket.serial }} {{ info.ticket.number }}.
      </div>

      <!-- Сверка со шлюзом (legacy: «Информация об оплате из сбербанка») -->
      <section class="bt-block">
        <h3>Информация об оплате из сбербанка</h3>
        <div v-if="info.bank.error" class="alert warn">{{ info.bank.error }}</div>
        <template v-else>
          <p>
            {{ info.order.sber_order_id }} Статус заказа: {{ info.bank.order_status }}
            Расшифровка: <b>{{ info.bank.status_text }}</b> {{ info.bank.action_code_description }}
            Платёж: {{ money(info.bank.amount) }} руб.
            Возврат: {{ money(info.bank.refunded) }} руб.
            <template v-if="info.bank.available !== undefined">
              · Доступно к возврату по шлюзу: <b>{{ money(info.bank.available) }} руб.</b>
            </template>
            <template v-if="info.bank.payment_state">
              · Состояние платежа: {{ info.bank.payment_state }}
            </template>
          </p>
          <!--
            Предупреждения про «прошло N часов» здесь нет: возврат, в
            отличие от отмены, шлюз принимает и через дни (проверено —
            возврат удержания прошёл спустя 67 часов). Прежние отказы
            давала потерянная копейка, а не давность платежа.
          -->
          <p v-if="info.order.fiscal_sign" class="muted">
            Чек покупки: ФП {{ info.order.fiscal_sign }}
          </p>
          <p v-else-if="info.order.receipt_needed" class="alert warn">
            Фискального чека покупки нет.
            <button
              v-if="can('back-ticket.repair')"
              class="btn small"
              :disabled="confirming"
              @click="registerSaleReceipt"
            >Пробить чек покупки</button>
          </p>
        </template>
      </section>

      <!--
        Билеты заказа и что с каждым сделано. Главное, чего не хватало:
        билет, по которому вернули ТОЛЬКО деньги (станция была недоступна),
        ничем не отличался от остальных — место на станции числится
        проданным, а по карточке этого не видно. Отсюда же переключение
        между билетами, не закрывая окно.
      -->
      <section v-if="info.tickets && info.tickets.length" class="bt-block">
        <h3>Билеты заказа</h3>
        <table class="pages-table">
          <thead>
            <tr>
              <th>Бланк</th>
              <th>Место</th>
              <th>Пассажир</th>
              <th class="fm-num">Цена</th>
              <th>Чек покупки</th>
              <th>Состояние</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="t in info.tickets"
              :key="t.serial + t.number"
              :class="{ 'bt-ticket-current': t.number === String(number), 'bt-ticket-warn': t.state === 'money_only' }"
            >
              <td class="nowrap">{{ t.serial }} {{ t.number }}</td>
              <td>{{ t.place }}</td>
              <td>{{ t.passenger }}</td>
              <td class="fm-num">
                {{ money(t.price_total) }}
                <div v-if="t.baggage > 0" class="muted small">
                  место {{ money(t.price) }} + багаж {{ money(t.price_bag) }}×{{ t.baggage }}
                </div>
              </td>
              <!--
                Чек ПОКУПКИ один на заказ (ККМ пробивает его на весь заказ),
                поэтому в строке билета показываем тот же признак — вопрос
                «пробит ли чек продажи» возникает именно здесь.
              -->
              <td>
                <span v-if="info.order.fiscal_sign" class="muted small">ФП {{ info.order.fiscal_sign }}</span>
                <template v-else>
                  <span class="badge-off">нет</span>
                  <div v-if="info.order.receipt_needed && can('back-ticket.repair')" class="bt-row-action">
                    <button class="btn small" :disabled="confirming" @click="registerSaleReceipt">
                      Пробить
                    </button>
                  </div>
                </template>
              </td>
              <td>
                <span v-if="t.state === 'active'" class="badge-on">действует</span>
                <template v-else-if="t.state === 'money_only'">
                  <span class="badge-off">деньги возвращены, на станции НЕ аннулирован</span>
                  <br /><span class="muted small">место занято — дошлите аннулирование</span>
                </template>
                <template v-else-if="t.state === 'bank_pending'">
                  <span class="badge-off">возвращён, деньги не ушли</span>
                </template>
                <template v-else-if="t.state === 'no_receipt'">
                  <span class="badge-on">возвращён</span>
                  <br /><span class="badge-off">чека нет</span>
                </template>
                <template v-else>
                  <span class="badge-on">возвращён</span>
                  <br /><span class="muted small">ФП {{ t.fiscal_sign }}</span>
                </template>
                <template v-if="t.refund_at">
                  <br /><span class="muted small">{{ t.refund_at }}, {{ money(t.refund_amount) }} руб.</span>
                </template>
              </td>
              <td class="fm-num">
                <button
                  v-if="t.number !== String(number)"
                  class="btn small"
                  type="button"
                  @click="openTicket(t)"
                >Открыть</button>
                <span v-else class="muted small">открыт</span>
              </td>
            </tr>
          </tbody>
        </table>
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
        <template v-else-if="done.baggage">
          <p>
            <b>Багаж возвращён: {{ done.count }} мест(а).</b> Билет остаётся действующим.
          </p>
          <p>Сумма: {{ money(done.amount) }} руб.</p>
        </template>
        <template v-else-if="done.penalty">
          <p><b>Удержание возвращено покупателю{{ done.left > 0 ? ' частично' : '' }}.</b></p>
          <p>
            Сумма: {{ money(done.amount) }} руб. из {{ money(done.withheld) }} руб. удержания
            (было удержано {{ done.parent_percent }}%).
            <template v-if="done.left > 0">
              <br />Осталось неотданным: <b>{{ money(done.left) }} руб.</b> — можно вернуть позже.
            </template>
          </p>
          <p class="muted">На автостанции такой возврат не фиксируется — ведомость уже закрыта.</p>
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
        <!--
          Билетов по заказу нет, а деньги списаны: дооформление не
          состоялось (например, станция была недоступна). Возвращать нечего
          — платёж надо отменить, и ждать автоматику (раз в 15 минут, не
          раньше часа с момента заказа) кассир не обязан.
        -->
        <section v-else-if="info.state === 'unfinished_payment'" class="bt-block">
          <h3>Заказ не оформлен, деньги списаны</h3>
          <p class="alert warn">{{ info.message }}</p>
          <p>
            Шлюз: {{ info.unfinished_payment.gateway_status_text || '—' }}
            <template v-if="info.unfinished_payment.refunded_amount">
              · уже возвращено {{ money(info.unfinished_payment.refunded_amount) }} руб.
            </template>
            <template v-if="info.order.status_reason">
              <br /><span class="muted">Причина: {{ info.order.status_reason }}</span>
            </template>
          </p>
          <button
            v-if="can('back-ticket.refund') && info.unfinished_payment.can_cancel"
            class="btn primary"
            :disabled="confirming"
            @click="cancelPayment"
          >
            {{ confirming ? 'Выполняется…' : 'Вернуть деньги покупателю' }}
          </button>
        </section>


        <!--
          Станция ответила отказом (билет уже возвращён, ведомость закрыта,
          рейс ушёл). Карточка при этом работает: ниже есть и список
          билетов, и прошлые возвраты — кассиру есть с чем работать.
        -->
        <section v-else-if="info.state === 'station_rejected'" class="bt-block">
          <h3>Станция отказала в возврате</h3>
          <p class="alert warn">{{ info.message }}</p>
          <p class="muted bt-hint">Ответ станции: {{ info.station_error }} ({{ info.station_error_code }})</p>
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
          <p>
            <label class="check" title="Чек можно пробить позже кнопкой у самой операции">
              <input v-model="withoutReceipt" type="checkbox" />
              без фискального чека
            </label>
          </p>
          <button v-if="can('back-ticket.refund')" class="btn primary" :disabled="confirming" @click="confirmRefund">
            {{ confirming ? 'Выполняется…' : 'Выполнить возврат' }}
          </button>
        </section>

        <!--
          База станции не отвечает (например, у станции нет интернета).
          Деньги покупателю вернуть всё равно надо — аннулирование билета
          дошлём, когда связь появится.
        -->
        <section v-else-if="info.state === 'refundable_without_station'" class="bt-block">
          <h3>Возврат билета — станция недоступна</h3>
          <p class="alert warn">{{ info.message }}</p>
          <p>
            Билет Серия {{ info.ticket.serial }} Номер {{ info.ticket.number }}<br />
            Пассажир: {{ info.ticket.passenger }}<br />
            Место: {{ info.ticket.place }}.<br />
            Цена: {{ money(info.ticket.price) }} руб.<br />
            Багажных мест: {{ info.ticket.baggage }} шт. по {{ money(info.ticket.price_bag) }} руб<br />
            на рейс <b>{{ info.trip_name }}</b><br />
            от <b>{{ info.order.from_name }}</b> до <b>{{ info.order.to_name }}</b>
          </p>
          <p class="muted bt-hint">Ответ станции: {{ info.station_error }}</p>
          <p>
            Процент удержаний:
            <select v-model.number="percent">
              <option :value="-1">Не выбран</option>
              <option v-for="v in info.variants" :key="v.percent" :value="v.percent">
                {{ v.percent }}% — к возврату {{ money(v.amount) }} руб.
              </option>
            </select>
            <span class="muted">станция рекомендуемый процент сейчас подсказать не может</span>
          </p>
          <p>
            <label class="check">
              <input v-model="withoutReceipt" type="checkbox" />
              без фискального чека
            </label>
          </p>
          <button
            v-if="can('back-ticket.refund')"
            class="btn primary"
            :disabled="confirming"
            @click="confirmWithoutStation"
          >
            {{ confirming ? 'Выполняется…' : 'Вернуть деньги без аннулирования' }}
          </button>
        </section>

        <!--
          Деньги вернули раньше, станция тогда не отвечала: теперь можно
          дослать аннулирование — место на станции освободится.
        -->
        <section v-else-if="info.state === 'station_annul_pending'" class="bt-block">
          <h3>Ждёт аннулирования на станции</h3>
          <p class="alert warn">{{ info.message }}</p>
          <p>
            Возврат от {{ info.refund.refunded_at }} на {{ money(info.refund.amount) }} руб.
            (удержано {{ info.refund.penalty_percent }}%).
            <template v-if="info.refund.money_refunded_at">
              <br />Деньги возвращены: {{ info.refund.money_refunded_at }}.
            </template>
            <template v-if="info.refund.station_annul_attempted_at">
              <br />Последняя попытка досылки: {{ info.refund.station_annul_attempted_at }}.
            </template>
            <template v-if="info.refund.station_annul_error">
              <br /><span class="muted">Причина: {{ info.refund.station_annul_error }}</span>
            </template>
          </p>
          <button
            v-if="can('back-ticket.refund')"
            class="btn primary"
            :disabled="confirming"
            @click="annulAtStation(info.refund.id)"
          >
            {{ confirming ? 'Выполняется…' : 'Дослать аннулирование на станцию' }}
          </button>
          <button
            v-if="can('back-ticket.repair') && !info.refund.money_refunded_at"
            class="btn"
            :disabled="confirming"
            @click="repairMoney"
          >
            Вернуть деньги по банку
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
          <button v-if="can('back-ticket.repair')" class="btn primary" :disabled="confirming" @click="repairMoney">
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

        <!--
          Багаж, возвращённый ВМЕСТЕ с билетом: отдельного возврата уже нет,
          но сказать об этом надо — иначе непонятно, куда делся раздел.
        -->
        <section v-if="info.baggage && info.baggage.refunded_with_ticket && info.baggage.items.length" class="bt-block">
          <h3>Багаж</h3>
          <p>
            Багажных квитанций по билету: {{ info.baggage.items.length }} по
            {{ money(info.baggage.price) }} руб. — возвращены вместе с билетом
            (деньги вошли в сумму возврата, бланки аннулированы на станции).
          </p>
        </section>

        <!--
          Возврат ТОЛЬКО багажа: отдельные бланки на станции, билет при этом
          продолжает действовать. Показываем, пока есть невозвращённые места.
        -->
        <section v-if="info.baggage && info.baggage.available > 0" class="bt-block">
          <h3>Возврат багажа</h3>
          <p>
            Багажных мест по билету: {{ info.baggage.items.length }},
            из них можно вернуть: <b>{{ info.baggage.available }}</b>
            по {{ money(info.baggage.price) }} руб.
          </p>
          <p>
            Вернуть мест:
            <select v-model.number="baggageCount">
              <option v-for="n in info.baggage.available" :key="n" :value="n">{{ n }}</option>
            </select>
            &nbsp; Процент удержаний:
            <select v-model.number="baggagePercent">
              <option :value="-1">Не выбран</option>
              <option v-for="v in info.baggage.variants" :key="v.percent" :value="v.percent">
                {{ v.percent }}%
              </option>
            </select>
            <template v-if="baggageAmount !== null">
              &nbsp; к возврату <b>{{ money(baggageAmount) }} руб.</b>
            </template>
          </p>
          <button
            v-if="can('back-ticket.refund')" class="btn primary"
            :disabled="confirming" @click="refundBaggage"
          >
            {{ confirming ? 'Выполняется…' : 'Вернуть багаж' }}
          </button>
          <p class="muted">Билет остаётся действующим — возвращаются только багажные квитанции.</p>
        </section>

        <!-- Все возвраты заказа и возврат удержания по ним -->
        <section v-if="info.refunds && info.refunds.length" class="bt-block">
          <h3>Возвраты по заказу</h3>
          <p>
            <label class="check" title="Действует на операции, запускаемые из этого блока">
              <input v-model="withoutReceipt" type="checkbox" />
              выполнять без фискального чека (чек можно пробить позже кнопкой «Пробить чек»)
            </label>
          </p>
          <table class="pages-table">
            <thead>
              <tr>
                <th>Когда</th>
                <th>Что вернули</th>
                <th>Бланк</th>
                <th class="fm-num">Сумма</th>
                <th class="fm-num">Удержано</th>
                <th>Состояние</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in info.refunds" :key="r.id">
                <td>{{ r.created_at }}</td>
                <td>{{ r.kind_label }}</td>
                <td>{{ r.serial }} {{ r.number }}</td>
                <td class="fm-num">{{ money(r.amount) }}</td>
                <td class="fm-num">{{ r.withheld > 0 ? money(r.withheld) : '—' }}</td>
                <td>
                  <span v-if="r.money_refunded" class="badge-on">деньги возвращены</span>
                  <span v-else class="badge-off">{{ r.status === 'bank_failed' ? 'банк отказал' : 'ожидает банк' }}</span>
                  <template v-if="r.money_refunded">
                    <br />
                    <span v-if="r.fiscal_sign" class="muted small">чек ФП {{ r.fiscal_sign }}</span>
                    <span v-else class="badge-off">чека нет</span>
                  </template>
                  <template v-if="r.station_annul_pending">
                    <br /><span class="badge-off">на станции не аннулирован</span>
                  </template>
                  <!-- Отказ банка: показываем ВСЮ технику, а не «Системную ошибку» -->
                  <template v-if="r.bank_error">
                    <br />
                    <span class="muted small">{{ r.bank_error }}</span>
                    <span v-if="r.attempts > 1" class="muted small"> · попыток: {{ r.attempts }}</span>
                    <br />
                    <button v-if="r.bank_response" class="btn small" type="button" @click="toggleDetails(r.id)">
                      {{ openedDetails.has(r.id) ? 'Скрыть подробности' : 'Подробности отказа' }}
                    </button>
                    <pre v-if="r.bank_response && openedDetails.has(r.id)" class="bt-bank-details">{{ bankDetailsText(r.bank_response) }}</pre>
                  </template>
                </td>
                <td class="fm-num">
                  <button
                    v-if="r.money_repairable && can('back-ticket.repair')"
                    class="btn small" :disabled="confirming" @click="repairRefund(r)"
                  >
                    Вернуть по банку
                  </button>
                  <template v-else-if="r.penalty_returnable && can('back-ticket.refund')">
                    <!-- Сумма правится: часть удержания можно отдать сейчас, остальное позже -->
                    <input
                      class="bt-penalty-sum" type="number" step="0.01" min="0.01" :max="r.penalty_left"
                      :value="penaltyInput(r)" @input="penaltyAmount[r.id] = $event.target.value"
                    />
                    <button class="btn small" :disabled="confirming" @click="refundPenalty(r)">
                      Вернуть удержание
                    </button>
                    <div v-if="r.penalty_left < r.withheld" class="muted small">
                      осталось {{ money(r.penalty_left) }} из {{ money(r.withheld) }}
                    </div>
                  </template>
                  <span v-else-if="r.penalty_returned" class="muted">удержание возвращено</span>

                  <!--
                    Чек по операции можно пробить вручную: очередь чеков
                    запускается не всегда, да и ККТ могла отказать
                    (закрытая смена, нет связи). Повторная команда второй
                    чек не пробьёт — идемпотентность на стороне KKM API.
                  -->
                  <div v-if="r.receipt_needed && can('back-ticket.repair')" class="bt-row-action">
                    <button class="btn small" :disabled="confirming" @click="registerReceipt(r)">
                      Пробить чек
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>
    </template>
  </div>
</template>

<style scoped>
/* Ответ станции при недоступности — мелким, это служебная подробность. */
.bt-hint { font-size: 12px; margin-top: -6px; }

/*
 * Сообщения карточки держатся у верхней кромки: карточка длинная, и
 * прокрутив её, кассир не видел ни ошибки, ни подтверждения.
 */
.bt-messages {
  position: sticky;
  top: 0;
  z-index: 5;
  padding-top: 6px;
  background: var(--bg, #f5f6f8);
}
.bt-messages .alert { display: flex; align-items: flex-start; gap: 10px; }
.bt-msg-close { margin-left: auto; flex: none; }

/* Техника отказа шлюза: моноширинно, с переносами, не растягивает таблицу. */
.bt-bank-details {
  margin: 6px 0 0;
  padding: 8px 10px;
  background: #fafafa;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 11px;
  white-space: pre-wrap;
  word-break: break-word;
  max-width: 420px;
}
.bt-row-action { margin-top: 6px; }

/* Открытый билет выделен, а билет «вернули только деньги» — заметен:
   по нему место на станции до сих пор числится проданным. */
.bt-ticket-current td { box-shadow: inset 0 0 0 1px var(--accent); }
.bt-ticket-warn td { background: #ffe3c2; }
.nowrap { white-space: nowrap; }
.small { font-size: 11px; }
.bt-block { margin: 14px 0; padding: 12px 16px; background: #fff; border: 1px solid var(--line, #d8dee8); border-radius: 8px; }
.bt-block h3 { margin: 0 0 8px; }
.bt-passengers { list-style: none; padding-left: 0; }
.bt-passengers li { padding: 2px 0; }
.bt-done { background: #f0fff0; }
.bt-penalty-sum { width: 90px; margin-right: 6px; text-align: right; }
</style>
