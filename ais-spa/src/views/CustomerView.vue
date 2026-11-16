<script setup>
/**
 * Карточка покупателя: кабинет (или адрес, на который покупали без
 * регистрации) — покупки, возвраты, способы входа, сохранённые пассажиры,
 * устройства и журнал того, что с кабинетом делали сотрудники.
 *
 * Действия разделены по тяжести последствий (каталог прав
 * shared/ais_permissions.php):
 *   customers.assist      — сброс пароля, подтверждение адреса, сессии;
 *   customers.edit        — смена e-mail и отвязка VK (с кодом);
 *   customers.impersonate — вход в кабинет от имени покупателя (с кодом).
 *
 * Номеров документов здесь нет: что лежит в билете, смотрят по ссылке на
 * печатную форму — в списке покупателей это не нужно.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  assistCustomer, fetchCustomer, fetchGuestCustomer, impersonateCustomer, updateCustomer,
} from '../siteApi'
import { can } from '../auth'

const props = defineProps({
  id: { type: String, default: '' },
  email: { type: String, default: '' },
})

const route = useRoute()
const isGuest = computed(() => !!(props.email || route.params.email))
const guestEmail = computed(() => decodeURIComponent(props.email || route.params.email || ''))

const PROVIDER_LABELS = { password: 'E-mail и пароль', vk: 'VK ID', phone: 'Телефон' }

const card = ref(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)

// Ссылка входа в кабинет: показывается один раз, живёт две минуты.
const impersonation = ref(null)
const newEmail = ref('')

const canAssist = computed(() => can('customers.assist'))
const canEdit = computed(() => can('customers.edit'))
const canImpersonate = computed(() => can('customers.impersonate'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    card.value = isGuest.value
      ? await fetchGuestCustomer(guestEmail.value)
      : await fetchCustomer(props.id || route.params.id)
    newEmail.value = card.value.email || ''
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

/** Общая обёртка действия: сообщение об итоге и свежая карточка из ответа. */
async function act(fn) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const data = await fn()
    notice.value = data.message || 'Готово'
    if (data.customer) {
      card.value = data.customer
      newEmail.value = card.value.email || ''
    }
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

/**
 * Задать покупателю пароль, который назвал сотрудник. Проверок сложности
 * нет — что ввели, то и будет (сервер только обрезает пробелы по краям).
 * Пароль показан открытым нарочно: его надо передать покупателю, как и
 * пароль сотрудника в разделе «Сотрудники».
 */
function setPassword() {
  const password = window.prompt(
    `Новый пароль для кабинета${card.value.email ? ` ${card.value.email}` : ''}.\n\n`
    + 'Подойдёт любой — длина и состав знаков не проверяются. Все прежние сессии '
    + 'покупателя закроются, пароль со старого сайта перестанет действовать.',
    '',
  )
  if (password === null || !password.trim()) return

  act(() => assistCustomer(card.value.id, 'reset_password', { password }))
}

/** Сбросить пароль вовсе: покупатель задаст его сам кодом из письма. */
function resetPassword() {
  const ok = window.confirm(
    'Сбросить пароль?\n\nПокупатель задаст новый сам: «Войти» → «Забыли пароль», код придёт '
    + 'на его адрес. Все его сессии при этом закроются.',
  )
  if (ok) act(() => assistCustomer(card.value.id, 'reset_password'))
}

function verifyEmail() {
  const ok = window.confirm(
    `Отметить адрес ${card.value.email} подтверждённым?\n\n`
    + 'По подтверждённому адресу кабинету становятся видны покупки, сделанные на него '
    + 'до регистрации. Делайте это только если убедились, что адрес принадлежит покупателю.',
  )
  if (ok) act(() => assistCustomer(card.value.id, 'verify_email'))
}

function logoutAll() {
  const ok = window.confirm('Завершить все сессии кабинета? На всех устройствах придётся войти заново.')
  if (ok) act(() => assistCustomer(card.value.id, 'logout_all'))
}

function unlinkVk() {
  const ok = window.confirm(
    card.value.has_password
      ? 'Отвязать вход через VK? Останется вход по e-mail и паролю.'
      : 'Отвязать вход через VK?\n\nУ кабинета НЕТ пароля — после отвязки покупателю придётся '
        + 'задать его через «Забыли пароль», иначе войти будет нечем.',
  )
  if (ok) act(() => updateCustomer(card.value.id, { action: 'unlink_vk' }))
}

function changeEmail() {
  const value = newEmail.value.trim()
  if (!value || value === card.value.email) return

  const ok = window.confirm(
    `Сменить e-mail кабинета на ${value}?\n\n`
    + 'Это и логин, и то, какие покупки кабинет видит: заказы, сделанные на новый адрес, '
    + 'станут ему видны, а сделанные на прежний — нет.',
  )
  if (ok) act(() => updateCustomer(card.value.id, { action: 'change_email', email: value }))
}

/**
 * Ссылка входа в кабинет от имени покупателя. Открываем её в новой
 * вкладке: АИС должна остаться на месте, а в кабинете сотрудник увидит
 * полосу «вы смотрите кабинет покупателя» — покупать и возвращать оттуда
 * сервер не даст.
 */
async function impersonate() {
  const ok = window.confirm(
    'Войти в кабинет покупателя от своего имени?\n\n'
    + 'Откроется сайт с его кабинетом: вы увидите то же, что видит он. Только просмотр — '
    + 'покупка, возврат и правка данных запрещены. Запись об этом попадёт в журнал.',
  )
  if (!ok) return

  busy.value = true
  error.value = ''
  try {
    impersonation.value = await impersonateCustomer(card.value.id)
    window.open(impersonation.value.url, '_blank', 'noopener')
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

const title = computed(() => (isGuest.value
  ? `Покупки на ${guestEmail.value}`
  : `Кабинет №${card.value?.id ?? ''}`))

watch(() => [route.params.id, route.params.email], load)

onMounted(() => {
  document.title = 'Покупатель · АИС'
  load()
})
</script>

<template>
  <div>
    <p class="cst-back">
      <router-link to="/customers">← Все покупатели</router-link>
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="notice" class="alert ok">{{ notice }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-if="card && !loading">
      <h1>{{ title }}</h1>

      <!-- Покупавший без регистрации: кабинета нет, менять нечего. -->
      <p v-if="isGuest" class="muted">
        Кабинета у этого адреса нет — покупали без регистрации.
        <template v-if="card.user_exists">
          Но кабинет с таким адресом существует: эти покупки видны в нём.
        </template>
        <template v-else>
          Если покупатель заведёт кабинет на этот адрес, покупки появятся в нём сами.
        </template>
      </p>

      <section class="panel">
        <div class="cst-grid">
          <div>
            <span class="muted">E-mail:</span>
            <template v-if="card.email">
              {{ card.email }}
              <span v-if="!isGuest" :class="card.email_verified ? 'badge-on' : 'badge-off'">
                {{ card.email_verified ? 'подтверждён' : 'не подтверждён' }}
              </span>
            </template>
            <template v-else>
              <span class="muted">нет (вход через VK, адрес не передан)</span>
            </template>
          </div>
          <div>
            <span class="muted">Телефон:</span> {{ card.phone || '—' }}
            <span v-if="!isGuest && card.phone && card.phone_verified" class="badge-on">подтверждён</span>
          </div>
          <div v-if="!isGuest"><span class="muted">Имя:</span> {{ card.name || '—' }}</div>
          <div v-if="!isGuest">
            <span class="muted">Пароль:</span>
            <template v-if="card.legacy_password">со старого сайта (ещё не входил)</template>
            <template v-else-if="card.has_password">задан</template>
            <template v-else>не задан</template>
          </div>
          <div v-if="!isGuest"><span class="muted">Регистрация:</span> {{ card.created_at }}</div>
          <div v-if="!isGuest">
            <span class="muted">Последний вход:</span> {{ card.last_login_at || 'ни разу' }}
          </div>
          <div><span class="muted">Первая покупка:</span> {{ card.stats.first_order_at || '—' }}</div>
          <div><span class="muted">Последняя покупка:</span> {{ card.stats.last_order_at || '—' }}</div>
        </div>

        <p class="cst-stats">
          Заказов: <b>{{ card.stats.orders }}</b> ·
          оформлено: <b>{{ card.stats.completed }}</b> ·
          на сумму <b>{{ card.stats.amount.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }}</b> ₽ ·
          возвратов: <b>{{ card.stats.refunds }}</b>
        </p>
      </section>

      <!-- Способы входа -->
      <section v-if="!isGuest" class="panel">
        <h2>Как входит</h2>
        <table v-if="card.identities.length" class="fb-table">
          <thead>
            <tr><th>Способ</th><th>Идентификатор</th><th>Привязан</th><th>Последний вход</th></tr>
          </thead>
          <tbody>
            <tr v-for="i in card.identities" :key="i.provider + i.provider_user_id">
              <td>{{ PROVIDER_LABELS[i.provider] || i.provider }}</td>
              <td>
                {{ i.provider_user_id }}
                <template v-if="i.name"><br /><span class="muted">{{ i.name }}</span></template>
              </td>
              <td class="nowrap">{{ i.created_at || '—' }}</td>
              <td class="nowrap">{{ i.last_login_at || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">
          Способов входа нет.
          <template v-if="card.legacy_password">
            Пароль переехал со старого сайта — покупатель войдёт им один раз, после чего он
            заменится на новый.
          </template>
        </p>

        <div v-if="card.sessions.length" class="cst-sessions">
          <span class="muted">Устройства:</span>
          <span v-for="s in card.sessions" :key="s.id" class="role-chip">
            {{ s.impersonated ? 'сотрудник в кабинете' : 'вход' }} от {{ s.created_at }}<template
              v-if="s.last_used_at"
            >, активность {{ s.last_used_at }}</template>
            <template v-if="!s.alive"> — просрочено</template>
          </span>
        </div>
      </section>

      <!-- Действия -->
      <section v-if="!isGuest && (canAssist || canEdit || canImpersonate)" class="panel">
        <h2>Помощь покупателю</h2>

        <div v-if="impersonation" class="alert warn">
          Ссылка входа в кабинет открыта в новой вкладке. Она одноразовая и действует
          {{ Math.round(impersonation.expires_in / 60) }} мин:
          <code class="secret">{{ impersonation.url }}</code>
        </div>

        <p class="row-actions">
          <button v-if="canAssist" class="btn" type="button" :disabled="busy" @click="setPassword">
            Задать пароль
          </button>
          <button v-if="canAssist" class="btn" type="button" :disabled="busy" @click="resetPassword">
            Сбросить пароль
          </button>
          <button
            v-if="canAssist && card.email && !card.email_verified"
            class="btn"
            type="button"
            :disabled="busy"
            @click="verifyEmail"
          >
            Подтвердить e-mail
          </button>
          <button
            v-if="canAssist && card.sessions.length"
            class="btn"
            type="button"
            :disabled="busy"
            @click="logoutAll"
          >
            Завершить все сессии
          </button>
          <button
            v-if="canEdit && card.identities.some((i) => i.provider === 'vk')"
            class="btn"
            type="button"
            :disabled="busy"
            @click="unlinkVk"
          >
            Отвязать VK
          </button>
          <button v-if="canImpersonate" class="btn primary" type="button" :disabled="busy" @click="impersonate">
            Войти в кабинет
          </button>
        </p>

        <div v-if="canEdit" class="cst-email">
          <label class="fb-field fb-grow">
            <span class="fb-label">E-mail кабинета (он же логин и то, какие покупки кабинет видит)</span>
            <input v-model="newEmail" type="email" placeholder="ivanov@mail.ru" />
          </label>
          <button class="btn" type="button" :disabled="busy || !newEmail.trim() || newEmail.trim() === card.email" @click="changeEmail">
            Сменить e-mail
          </button>
        </div>
      </section>

      <!-- Покупки -->
      <section class="panel">
        <h2>Покупки</h2>
        <p v-if="!card.orders.length" class="muted">Покупок нет.</p>
        <table v-else class="fb-table">
          <thead>
            <tr>
              <th>Заказ</th><th>Рейс</th><th>Поездка</th>
              <th class="num">Сумма</th><th>Состояние</th><th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in card.orders" :key="o.id">
              <td>
                <span class="muted">{{ o.created_at }}</span><br />
                <code class="cst-id">{{ o.id }}</code>
                <span v-if="o.legacy" class="muted"> · со старого сайта</span>
              </td>
              <td>
                {{ o.route }}
                <br /><span class="muted">билетов: {{ o.tickets }}</span>
              </td>
              <td class="nowrap">{{ o.date_start }} {{ o.time_start }}</td>
              <td class="num">{{ o.total_price.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }}</td>
              <td>{{ o.status_title }}</td>
              <td class="pages-actions">
                <!-- Печатная форма живёт на сайте, возврат — в АИС. -->
                <a v-if="o.print_url" class="btn small" :href="o.print_url" target="_blank" rel="noopener">Билеты</a>
                <router-link class="btn small" :to="o.refund_url">Возврат</router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Возвраты -->
      <section v-if="card.refunds && card.refunds.length" class="panel">
        <h2>Возвраты</h2>
        <table class="fb-table">
          <thead>
            <tr><th>Когда</th><th>Бланк</th><th>Вид</th><th class="num">Сумма</th><th>Состояние</th><th /></tr>
          </thead>
          <tbody>
            <tr v-for="r in card.refunds" :key="r.id">
              <td class="nowrap">{{ r.created_at }}</td>
              <td>{{ r.serial }} {{ r.number }}</td>
              <td>{{ r.kind }}</td>
              <td class="num">{{ r.amount.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }}</td>
              <td>{{ r.status }}</td>
              <td class="pages-actions">
                <router-link class="btn small" :to="r.refund_url">Открыть</router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Сохранённые пассажиры -->
      <section v-if="!isGuest && card.passengers.length" class="panel">
        <h2>Сохранённые пассажиры</h2>
        <p class="muted">
          Подставляются в форму покупки. Номера документов здесь не показываем — они есть
          в билете.
        </p>
        <ul class="cst-list">
          <li v-for="p in card.passengers" :key="p.id">
            {{ p.name }}
            <span class="muted">· последний раз использован {{ p.last_used_at || '—' }}</span>
          </li>
        </ul>
      </section>

      <!-- Журнал -->
      <section v-if="!isGuest && card.actions.length" class="panel">
        <h2>Что делали сотрудники</h2>
        <ul class="cst-list">
          <li v-for="(a, at) in card.actions" :key="at">
            <span class="muted">{{ a.created_at }}</span> · {{ a.title }} ·
            <b>{{ a.employee }}</b>
            <template v-if="a.details">
              <span class="muted"> · {{ Object.entries(a.details).map(([k, v]) => `${k}: ${v}`).join(', ') }}</span>
            </template>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.cst-back { margin: 0 0 6px; }
.cst-grid { display: flex; flex-wrap: wrap; gap: 6px 24px; font-size: 14px; }
.cst-stats { margin: 12px 0 0; font-size: 14px; }
.cst-sessions { margin-top: 10px; font-size: 13px; }
.cst-email { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; margin-top: 12px; }
.cst-list { margin: 8px 0 0; padding-left: 18px; font-size: 14px; }
.cst-list li { margin-bottom: 4px; }
.cst-id { font-size: 12px; word-break: break-all; }
.num { text-align: right; }
</style>
