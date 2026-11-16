<script setup>
/**
 * Покупатели сайта: кабинеты и покупавшие БЕЗ регистрации одним списком.
 *
 * Почему вместе: в поддержку звонят по покупке, а не по кабинету, и
 * человек обычно не помнит, регистрировался он или нет. Гостевых адресов
 * несколько десятков (перенос со старого сайта связал покупки с
 * кабинетами по e-mail), поэтому отдельный раздел им не нужен.
 *
 * В legacy такой страницы не было вовсе: АИС работала без авторизации, а
 * база покупателей правилась запросами к Firebird руками.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { fetchCustomers } from '../siteApi'

const router = useRouter()

const LOGINS = [
  { value: '', label: 'любой способ входа' },
  { value: 'password', label: 'пароль' },
  { value: 'vk', label: 'VK' },
  { value: 'legacy', label: 'пароль со старого сайта (ещё не входил)' },
  { value: 'none', label: 'войти нечем' },
]

const PROVIDER_LABELS = { password: 'пароль', vk: 'VK', phone: 'телефон' }

const filters = ref({
  q: '', kind: 'all', login: '', has_orders: '', email_unverified: false,
  date_from: '', date_to: '',
})
const page = ref(1)

const items = ref([])
const counts = ref({})
const total = ref(0)
const pages = ref(1)
const loading = ref(false)
const error = ref('')

const hasFilters = computed(() => filters.value.q || filters.value.login
  || filters.value.has_orders !== '' || filters.value.email_unverified
  || filters.value.date_from || filters.value.date_to || filters.value.kind !== 'all')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchCustomers({
      ...filters.value,
      email_unverified: filters.value.email_unverified ? 1 : '',
      page: page.value,
    })
    items.value = data.items
    counts.value = data.counts
    total.value = data.total
    pages.value = data.pages
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// Смена вкладки или фильтра возвращает на первую страницу: иначе после
// сужения выборки открывался бы пустой «пятый лист».
watch(() => [filters.value.kind, filters.value.login, filters.value.has_orders,
  filters.value.email_unverified], () => { page.value = 1; load() })

function apply() {
  page.value = 1
  load()
}

function reset() {
  filters.value = {
    q: '', kind: 'all', login: '', has_orders: '', email_unverified: false,
    date_from: '', date_to: '',
  }
  apply()
}

function go(delta) {
  const next = page.value + delta
  if (next < 1 || next > pages.value) return
  page.value = next
  load()
}

/** Кабинет — на карточку по id, гость — на карточку по адресу. */
function open(row) {
  router.push(row.kind === 'user'
    ? `/customers/${row.id}`
    : `/customers/guest/${encodeURIComponent(row.email)}`)
}

function providerList(row) {
  if (row.kind === 'guest') return 'без кабинета'
  if (!row.providers.length) return row.legacy_only ? 'старый пароль' : 'войти нечем'

  const names = row.providers.map((p) => PROVIDER_LABELS[p] || p)
  if (row.legacy_only) names.push('старый пароль')

  return names.join(', ')
}

onMounted(() => {
  document.title = 'Покупатели сайта · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Покупатели сайта</h1>
    <p class="muted">
      Личные кабинеты и покупавшие без регистрации — по ним и ищем, когда звонят
      по покупке. Нажмите строку, чтобы открыть карточку: покупки, возвраты,
      способы входа и помощь со входом.
    </p>

    <div class="fb-tabs">
      <button
        v-for="tab in [
          { value: 'all', label: 'Все', count: counts.all },
          { value: 'users', label: 'Кабинеты', count: counts.users },
          { value: 'guests', label: 'Без кабинета', count: counts.guests },
        ]"
        :key="tab.value"
        class="fb-tab"
        :class="{ active: filters.kind === tab.value }"
        @click="filters.kind = tab.value"
      >
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="fb-count">{{ tab.count }}</span>
      </button>
      <span v-if="counts.vk" class="muted cst-vk">через VK: {{ counts.vk }}</span>
    </div>

    <div class="fb-filters">
      <label class="fb-field fb-grow">
        <span class="fb-label">Поиск по e-mail, телефону, имени и номеру кабинета</span>
        <input
          v-model="filters.q"
          type="search"
          placeholder="например, ivanov@mail.ru или 9201234567"
          @keyup.enter="apply"
        />
      </label>
      <label class="fb-field">
        <span class="fb-label">Вход</span>
        <select v-model="filters.login">
          <option v-for="o in LOGINS" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </label>
      <label class="fb-field">
        <span class="fb-label">Покупки</span>
        <select v-model="filters.has_orders">
          <option value="">любые</option>
          <option value="1">есть</option>
          <option value="0">нет</option>
        </select>
      </label>
      <label class="fb-field">
        <span class="fb-label">Появился с</span>
        <input v-model="filters.date_from" type="date" />
      </label>
      <label class="fb-field">
        <span class="fb-label">по</span>
        <input v-model="filters.date_to" type="date" />
      </label>
      <button class="btn primary" @click="apply">Показать</button>
      <button v-if="hasFilters" class="btn" @click="reset">Сбросить</button>
    </div>

    <p>
      <label class="check">
        <input v-model="filters.email_unverified" type="checkbox" />
        только с неподтверждённым e-mail
      </label>
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="!loading">
      <p v-if="!items.length" class="muted">Никого не нашлось.</p>

      <table v-else class="fb-table">
        <thead>
          <tr>
            <th>E-mail</th>
            <th>Телефон и имя</th>
            <th>Вход</th>
            <th class="num">Покупки</th>
            <th>Последняя покупка</th>
            <th>Появился</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in items"
            :key="row.kind + (row.id ?? row.email)"
            class="fb-row"
            :class="{ 'cst-guest': row.kind === 'guest' }"
            @click="open(row)"
          >
            <td>
              <template v-if="row.email">{{ row.email }}</template>
              <span v-else class="muted">без адреса (вход через VK)</span>
              <template v-if="row.kind === 'user'">
                <br />
                <span class="muted">кабинет №{{ row.id }}</span>
                <span v-if="row.email && !row.email_verified" class="badge-off"> не подтверждён</span>
              </template>
            </td>
            <td>
              {{ row.phone || '—' }}
              <template v-if="row.name"><br /><span class="muted">{{ row.name }}</span></template>
            </td>
            <td class="muted">{{ providerList(row) }}</td>
            <td class="num">
              <b>{{ row.orders_completed }}</b>
              <span v-if="row.orders_total !== row.orders_completed" class="muted">
                из {{ row.orders_total }}
              </span>
            </td>
            <td class="nowrap">{{ row.last_order_at || '—' }}</td>
            <td class="nowrap">
              {{ row.created_at || '—' }}
              <template v-if="row.last_login_at">
                <br /><span class="muted">вход: {{ row.last_login_at }}</span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="pages > 1" class="fb-pager">
        <button class="btn small" :disabled="page <= 1" @click="go(-1)">←</button>
        <span class="muted">Страница {{ page }} из {{ pages }} · всего {{ total }}</span>
        <button class="btn small" :disabled="page >= pages" @click="go(1)">→</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Фильтры и таблица — те же классы, что у «Обращений с сайта» (style.css):
   два похожих списка в одной АИС не должны выглядеть по-разному. */
.fb-field select {
  padding: 7px 9px; border: 1px solid var(--line-strong); border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; width: 100%;
}
.num { text-align: right; }
/* Строка без кабинета — серым: это не зарегистрированный покупатель, а
   только адрес, на который покупали. */
.cst-guest td { background: #f4f4f4; }
.cst-vk { margin-left: auto; font-size: 13px; align-self: center; }
</style>
