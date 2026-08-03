<script setup lang="ts">
// Печатная форма билетов (перенос legacy print.php): страница A4 на каждый
// билет + квитанция на каждое место багажа. Без шапки и подвала сайта.
definePageMeta({ layout: false })

const route = useRoute()
const api = useApi()

useSeoMeta({ title: 'Печать билета' })

interface BaggageTicket {
  serial: string
  number: string
}

interface PrintTicket {
  serial: string
  number: string
  place: string | number
  tariff: string
  price: number
  baggage: number
  price_bag: number
  baggage_tickets: BaggageTicket[]
  passenger: string
  citizenship: string
  doc_name: string
  doc_number: string
  birth_date: string
  gender: string
}

interface PrintData {
  order_id: string
  from_name: string
  to_name: string
  trip_name: string
  carrier: string
  date_start: string
  time_start: string
  date_end: string | null
  time_end: string | null
  sold_at: string
  back_code: string | null
  fiscal_sign: string | null
  total_price: number
  depersonalized: boolean
  tickets: PrintTicket[]
}

const { data, error } = await useAsyncData(`print-${route.params.id}`, () =>
  api<{ success: boolean, data: PrintData }>(`/orders/${route.params.id}/print`))

const order = computed(() => data.value?.data ?? null)

const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']
const DAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб']

/** «2026-08-03» → «03 августа 2026, пн» (формат legacy print.php). */
function rusDate(iso: string | null) {
  if (!iso) return ''
  const [y, m, d] = iso.split('-').map(Number)
  const date = new Date(y!, m! - 1, d)
  return `${String(d).padStart(2, '0')} ${MONTHS[m! - 1]} ${y}, ${DAYS[date.getDay()]}`
}

function money(v: number) {
  return v.toFixed(2).replace('.', ',')
}

/** Квитанция на каждое место багажа (legacy: страница на единицу багажа). */
function baggageUnits(t: PrintTicket): BaggageTicket[] {
  if (t.baggage_tickets.length) return t.baggage_tickets
  return Array.from({ length: t.baggage }, () => ({ serial: t.serial, number: t.number }))
}

const receiptUrl = computed(() => order.value
  ? `https://receipt.taxcom.ru/v01/show?fp=${order.value.fiscal_sign ?? ''}&s=${order.value.total_price.toFixed(2)}`
  : '')

// Ссылка попадает в билет и на печать, поэтому адрес — публичный из конфига,
// а не origin запроса (для PDF это внутренний http://frontend:3000).
const siteUrl = useRuntimeConfig().public.siteUrl.replace(/\/+$/, '')

function backUrl(t: PrintTicket) {
  return `${siteUrl}/backbycode?code=${order.value?.back_code ?? ''}${t.number}`
}

function printPage() {
  window.print()
}
</script>

<template>
  <div class="print-root">
    <div v-if="error" class="print-error">
      Ошибка! Информация по заказу не найдена
    </div>

    <template v-else-if="order">
      <div class="print_div">
        <a class="print_b" href="#" @click.prevent="printPage">Распечатать билеты</a>
      </div>

      <article class="main-wrapper">
        <template v-for="t in order.tickets" :key="t.number">
          <!-- Электронный билет -->
          <div class="ticket-print-a4">
            <div class="ticket-print-a4-header org-header">
              АО «Орелавтотранс»<br>ИНН 5751001984
            </div>
            <div class="ticket-print-a4-header">
              <div class="ticket-print-a4-header-h">
                <div class="ticket-print-a4-header-h-lbl">Электронный билет на&nbsp;автобус</div>
                <div class="ticket-print-a4-header-h-number">Серия {{ t.serial }} № {{ t.number }}</div>
                <div class="ticket-print-a4-header-h-number">Вид ТС: Автобус</div>
              </div>
            </div>
            <div class="ticket-print-a4-route"><strong>Маршрут</strong>: {{ order.trip_name }}</div>
            <div class="ticket-print-a4-places">
              <div class="ticket-print-a4-places-item" style="display:block;">
                Место: <strong>{{ t.place }}</strong><br>
                Перевозчик: <strong>{{ order.carrier }}</strong>
              </div>
            </div>
            <div class="ticket-print-a4-route-time">
              <div class="ticket-print-a4-route-time-left">
                <div class="route-point">Отправление</div>
                <div class="route-point">{{ order.from_name }}</div>
                <div class="route-time-label">Время отправления</div>
                <div class="route-time">{{ order.time_start }}</div>
                <div class="route-date">{{ rusDate(order.date_start) }}</div>
              </div>
              <div class="ticket-print-a4-route-time-right">
                <div class="route-point">Прибытие</div>
                <div class="route-point">{{ order.to_name }}</div>
                <div class="route-time-label">Время прибытия</div>
                <div class="route-time">{{ order.time_end }}</div>
                <div class="route-date">{{ rusDate(order.date_end) }}</div>
              </div>
            </div>
            <div class="ticket-print-a4-passenger">
              Пассажир: {{ t.passenger }}<br>
              Гражданство: {{ t.citizenship }}<br>
              Вид документа: {{ t.doc_name }}<br>
              Номер документа: {{ t.doc_number }}<br>
              Дата рождения: {{ t.birth_date }}<br>
              Пол: {{ t.gender }}<br>
              Тариф: {{ t.tariff }}<br>
              Сумма: {{ money(t.price) }} руб.<br>
              <template v-if="t.baggage">
                Багаж\ручная кладь: {{ t.baggage }} X {{ money(t.price_bag) }}={{ money(t.baggage * t.price_bag) }} руб.<br>
              </template>
              Дата продажи: {{ order.sold_at }}<br>
            </div>
            <!-- В legacy print.php здесь опечатка «для для» — исправлена. -->
            Код для возврата билета: {{ order.back_code }}{{ t.number }}<br>
            Возврат билета (URL): <a target="_blank" :href="backUrl(t)">{{ backUrl(t) }}</a><br>
            Электронный чек(URL): <a target="_blank" :href="receiptUrl">{{ receiptUrl }}</a>
          </div>

          <!-- Багажные квитанции -->
          <div v-for="(bag, i) in baggageUnits(t)" :key="`${t.number}-bag-${i}`" class="ticket-print-a4">
            <div class="ticket-print-a4-header org-header">
              АО «Орелавтотранс»<br>ИНН 5751001984
            </div>
            <div class="ticket-print-a4-header">
              <div class="ticket-print-a4-header-h">
                <br><br>
                <div class="ticket-print-a4-header-h-lbl">Квитанция на провоз одного места<br>багажа/ручной клади в автобусе</div>
                <div class="ticket-print-a4-header-h-number">Серия {{ bag.serial }} № {{ bag.number }}</div>
                <div class="ticket-print-a4-header-h-number">Вид ТС: Автобус</div>
              </div>
            </div>
            <div class="ticket-print-a4-route"><strong>Маршрут</strong>: {{ order.trip_name }}</div>
            <div class="ticket-print-a4-places">
              <div class="ticket-print-a4-places-item">
                Перевозчик: <strong>{{ order.carrier }}</strong>
              </div>
            </div>
            <div class="ticket-print-a4-route-time">
              <div class="ticket-print-a4-route-time-left">
                <div class="route-point">Отправление</div>
                <div class="route-point">{{ order.from_name }}</div>
                <div class="route-time-label">Время отправления</div>
                <div class="route-time">{{ order.time_start }}</div>
                <div class="route-date">{{ rusDate(order.date_start) }}</div>
              </div>
              <div class="ticket-print-a4-route-time-right">
                <div class="route-point">Прибытие</div>
                <div class="route-point">{{ order.to_name }}</div>
                <div class="route-time-label">Время прибытия</div>
                <div class="route-time">{{ order.time_end }}</div>
                <div class="route-date">{{ rusDate(order.date_end) }}</div>
              </div>
            </div>
            <div class="ticket-print-a4-passenger">
              Пассажир: {{ t.passenger }}<br>
              Дата рождения: {{ t.birth_date }}<br>
              Пол: {{ t.gender }}<br>
              Стоимость провоза 1 места: {{ money(t.price_bag) }} руб.<br>
              Дата продажи: {{ order.sold_at }}<br>
              Объявленная ценность одного места багажа прописью:____________________________________________________________<br><br>
              дополнительная плата прописью:___________________________________<br><br>
              Принял багаж водитель ________________подпись_____________________
            </div>
          </div>
        </template>
      </article>
    </template>
  </div>
</template>

<style scoped>
/* Вёрстка legacy print.php сохранена 1:1 */
.print-root {
  font-family: 'Open Sans', Arial, Helvetica, sans-serif;
  background: #fff;
  color: #000;
  padding: 0;
  margin: 0;
}
.print-error {
  background-color: #fff0f0;
  padding: 8px;
}
.print_div { text-align: center; }
.print_b {
  display: block;
  margin: 0 auto;
  padding-top: 20px;
  background-color: #e0e0e0;
  width: 200px;
  height: 50px;
  font-weight: bold;
  text-decoration: none;
  border-radius: 8px;
  text-align: center;
  color: #000;
}
.ticket-print-a4 {
  width: 20cm;
  min-height: 28cm;
  padding: 1cm 1.5cm;
  margin: 0 auto;
  box-sizing: border-box;
  page-break-after: always;
}
/* Разрыв после ПОСЛЕДНЕГО бланка даёт лишнюю пустую страницу (был и в legacy). */
.ticket-print-a4:last-child {
  page-break-after: auto;
}
.ticket-print-a4-header {
  display: table;
  width: 100%;
}
.ticket-print-a4-header > * {
  display: table-cell;
  vertical-align: bottom;
}
.org-header {
  text-align: center;
  font-size: 14pt;
}
.ticket-print-a4-header-h-lbl {
  font-size: 20pt;
  font-weight: bold;
  line-height: 24pt;
}
.ticket-print-a4-header-h-number {
  font-size: 18pt;
  line-height: 22pt;
  margin-top: 2pt;
}
.ticket-print-a4-route {
  font-size: 18pt;
  line-height: 24pt;
  margin: 30pt 0 34pt;
}
.ticket-print-a4-places { text-align: left; }
.ticket-print-a4-places-item {
  display: inline-block;
  vertical-align: middle;
  font-size: 18pt;
}
.ticket-print-a4-places-item strong {
  font-size: 18pt;
  font-weight: 600;
}
.ticket-print-a4-route-time {
  display: table;
  width: 100%;
  margin: 34pt 0 21pt;
}
.ticket-print-a4-route-time > * {
  display: table-cell;
  vertical-align: top;
}
.ticket-print-a4-route-time-left { text-align: left; }
.ticket-print-a4-route-time-right { text-align: right; }
.ticket-print-a4-route-time .route-time {
  font-size: 24pt;
  font-weight: 700;
  line-height: 31pt;
}
.ticket-print-a4-route-time .route-date {
  font-size: 16pt;
  font-weight: 400;
  line-height: 22pt;
  color: #4a4a4a;
}
.route-point {
  margin-top: 10pt;
  font-size: 16pt;
  line-height: 19pt;
  font-weight: 700;
}
.ticket-print-a4-passenger {
  padding: 17pt 0;
  border-top: 1px solid #ccc;
  border-bottom: 1px solid #ccc;
  font-size: 14pt;
  line-height: 21pt;
  margin-top: 21pt;
}

@page {
  size: A4;
  margin: 0;
}
@media print {
  .print_div { display: none; }
  .ticket-print-a4 {
    margin: 0;
    border: initial;
    border-radius: initial;
    width: initial;
    min-height: initial;
    box-shadow: initial;
    background: initial;
    box-sizing: border-box;
    page-break-after: always;
  }
  .ticket-print-a4:last-child {
    page-break-after: auto;
  }
}
</style>
