<script setup>
/**
 * Возвраты, по которым станция ещё не знает об аннулировании.
 *
 * Такие появляются, когда база станции недоступна (у станции пропал
 * интернет), а покупатель просит деньги: деньги возвращаются сразу, а
 * аннулирование бланка в ведомости станции досылается отсюда, когда связь
 * появится. Пока возврат в этом списке, место на станции числится
 * проданным — его не продадут второй раз, но и свободным оно не станет.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchPendingRefunds, refundAction } from '../siteApi'
import { can } from '../auth'

const router = useRouter()

const items = ref([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref('')

const canAnnul = computed(() => can('back-ticket.refund'))
const total = computed(() => items.value.reduce((sum, r) => sum + r.amount, 0))

async function load() {
  loading.value = true
  error.value = ''
  try {
    items.value = (await fetchPendingRefunds()).items
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

/** Дослать аннулирование: станция должна быть на связи. */
async function annul(row) {
  const ok = window.confirm(
    `Дослать аннулирование билета ${row.serial} ${row.number} на станцию?\n\n`
    + 'Станция должна быть на связи. Место освободится, билет станет возвращённым '
    + 'и в ведомости станции.',
  )
  if (!ok) return

  busy.value = row.refund_id
  error.value = ''
  notice.value = ''
  try {
    const result = await refundAction('annul', { order_id: row.order_id, refund_id: row.refund_id })
    notice.value = result.message
    await load()
  } catch (e) {
    // Станция всё ещё недоступна — это ожидаемо, текст придёт от сервера.
    error.value = e.message
    await load()
  } finally {
    busy.value = ''
  }
}

function money(value) {
  return Number(value || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 })
}

onMounted(() => {
  document.title = 'Возвраты без станции · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Возвраты без станции</h1>
    <p class="muted">
      Деньги покупателям возвращены, а бланки на станции ещё не аннулированы —
      так бывает, когда у станции нет связи. Пока возврат здесь, место на рейсе
      числится проданным. Дошлите аннулирование, когда станция снова на связи.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="notice" class="alert ok">{{ notice }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <p v-if="!items.length" class="muted">
        Незакрытых возвратов нет — на станциях аннулировано всё, за что вернули деньги.
      </p>

      <template v-else>
        <p>
          Ждут аннулирования: <b>{{ items.length }}</b> на сумму <b>{{ money(total) }}</b> руб.
        </p>

        <table class="fb-table">
          <thead>
            <tr>
              <th>Возврат</th>
              <th>Бланк</th>
              <th>Рейс</th>
              <th class="num">Сумма</th>
              <th>Деньги</th>
              <th>Попытка досылки</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in items" :key="row.refund_id">
              <td class="nowrap">
                {{ row.created_at }}
                <br /><span class="muted">станция {{ row.awtcod }}</span>
              </td>
              <td>
                {{ row.serial }} {{ row.number }}
                <br /><span class="muted">место {{ row.place }}, {{ row.kind === 'baggage' ? 'багаж' : 'билет' }}</span>
              </td>
              <td>
                {{ row.trip }}
                <br /><span class="muted">поездка {{ row.date_start }}</span>
              </td>
              <td class="num">{{ money(row.amount) }}</td>
              <td class="nowrap">
                <template v-if="row.money_refunded_at">возвращены {{ row.money_refunded_at }}</template>
                <span v-else class="badge-off">не возвращены</span>
              </td>
              <td>
                {{ row.attempted_at || '—' }}
                <template v-if="row.error">
                  <br /><span class="muted">{{ row.error }}</span>
                </template>
              </td>
              <td class="pages-actions">
                <button
                  v-if="canAnnul"
                  class="btn small primary"
                  :disabled="busy === row.refund_id"
                  @click="annul(row)"
                >
                  {{ busy === row.refund_id ? '…' : 'Дослать' }}
                </button>
                <router-link class="btn small" :to="row.refund_url">Карточка</router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </template>
  </div>
</template>

<style scoped>
/* Таблица — общие классы списка из style.css. */
.num { text-align: right; }
</style>
