<script setup>
/**
 * Сотрудники АИС: кого пускаем в систему и с какими ролями.
 *
 * Первый администратор заводится на сервере командой `php artisan ais:admin`
 * (на пустой системе входить ещё некому), дальше всех заводят здесь.
 */
import { computed, onMounted, ref } from 'vue'
import {
  createEmployee, deleteEmployee, deleteEmployeeFastToken, fetchEmployees, fetchRoles,
  logoutEmployeeSessions, resetEmployeeConfirmCode, resetEmployeeFastToken,
  resetEmployeePassword, updateEmployee,
} from '../api'
import { can, employee as me, startImpersonation } from '../auth'

const employees = ref([])
const roles = ref([])
const loading = ref(false)
const error = ref('')
const notice = ref('')

// Открытая форма: null — закрыта, {…} — правка или создание.
const form = ref(null)
const formErr = ref('')
const saving = ref(false)

// Выданный секрет показывается один раз, сразу после выдачи.
const issued = ref(null)

const canEdit = computed(() => can('employees.edit'))
const canReset = computed(() => can('employees.reset'))
const canDelete = computed(() => can('employees.delete'))
const canImpersonate = computed(() => can('employees.impersonate'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [list, roleList] = await Promise.all([fetchEmployees(), fetchRoles()])
    employees.value = list.employees
    roles.value = roleList.roles
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function startCreate() {
  issued.value = null
  formErr.value = ''
  form.value = {
    id: null, login: '', full_name: '', position: '', email: '', phone: '',
    is_active: true, role_ids: [], password: '', confirm_code: '',
    fast_token_enabled: false, fast_token: '',
  }
}

function startEdit(row) {
  issued.value = null
  formErr.value = ''
  form.value = {
    id: row.id,
    login: row.login,
    full_name: row.full_name || '',
    position: row.position || '',
    email: row.email || '',
    phone: row.phone || '',
    is_active: row.is_active,
    role_ids: [...row.role_ids],
  }
}

function toggleRole(id) {
  const list = form.value.role_ids
  const at = list.indexOf(id)
  if (at === -1) list.push(id)
  else list.splice(at, 1)
}

async function save() {
  saving.value = true
  formErr.value = ''
  try {
    if (form.value.id === null) {
      const data = await createEmployee(form.value)
      if (data.fast_token) issued.value = { title: 'Токен быстрого входа', value: data.fast_token }
    } else {
      await updateEmployee(form.value.id, form.value)
    }
    form.value = null
    notice.value = 'Сохранено'
    await load()
  } catch (e) {
    formErr.value = e.message
  } finally {
    saving.value = false
  }
}

async function act(label, fn) {
  error.value = ''
  notice.value = ''
  try {
    const result = await fn()
    notice.value = label
    await load()

    return result
  } catch (e) {
    error.value = e.message

    return null
  }
}

async function resetPassword(row) {
  const password = window.prompt(
    `Новый пароль для «${row.login}». Подойдёт фраза из нескольких слов — состав знаков не проверяется.`,
    '',
  )
  if (password === null || !password.trim()) return

  await act(`Пароль сотрудника «${row.login}» сброшен, его сессии закрыты.`,
    () => resetEmployeePassword(row.id, password))
}

async function resetCode(row) {
  const code = window.prompt(`Новый код подтверждения для «${row.login}» (3–20 любых знаков).`, '')
  if (code === null || !code.trim()) return

  await act(`Код подтверждения сотрудника «${row.login}» изменён.`,
    () => resetEmployeeConfirmCode(row.id, code))
}

async function issueToken(row) {
  const data = await act(`Токен сотруднику «${row.login}» выдан.`, () => resetEmployeeFastToken(row.id, ''))
  if (data?.token) {
    issued.value = {
      title: `Токен быстрого входа для «${row.login}»`,
      value: data.token,
      link: `${window.location.origin}/?token=${data.token}`,
    }
  }
}

async function dropToken(row) {
  if (!window.confirm(`Отключить токен быстрого входа у «${row.login}»?`)) return

  await act(`Токен сотрудника «${row.login}» отключён.`, () => deleteEmployeeFastToken(row.id))
}

async function closeSessions(row) {
  if (!window.confirm(`Закрыть все сессии «${row.login}»? Ему придётся войти заново.`)) return

  await act(`Сессии сотрудника «${row.login}» закрыты.`, () => logoutEmployeeSessions(row.id))
}

async function remove(row) {
  if (!window.confirm(`Удалить сотрудника «${row.login}»? Отменить это нельзя.`)) return

  await act(`Сотрудник «${row.login}» удалён.`, () => deleteEmployee(row.id))
}

/**
 * Поработать в АИС от имени сотрудника: увидеть его меню, его отчёты, его
 * кнопки. Роль на словах не проверишь, а «у меня этого нет» — самая
 * частая жалоба.
 *
 * Режим наблюдательный: сервер не даст из него ничего изменить. После
 * старта приложение перезагружается — у сотрудника другие права и другое
 * меню (см. auth.js).
 */
async function impersonate(row) {
  const ok = window.confirm(
    `Работать в АИС от имени «${row.login}»?\n\n`
    + 'Вы увидите АИС его глазами: его разделы, отчёты и кнопки. Изменять что-либо '
    + 'в этом режиме нельзя — вернуться к себе можно кнопкой в полосе сверху. '
    + 'Запись об этом попадёт в журнал.',
  )
  if (!ok) return

  error.value = ''
  try {
    await startImpersonation(row.id)
  } catch (e) {
    error.value = e.message
  }
}

function stamp(value) {
  return value ? new Date(value).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}

onMounted(() => {
  document.title = 'Сотрудники · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Сотрудники</h1>
    <p class="muted">
      Пользователи АИС. Доступ к отчётам и разделам администрирования определяется ролями
      — их набор правится в разделе «Роли и права».
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="notice" class="alert ok">{{ notice }}</div>

    <!-- Секрет виден только сейчас: в базе он лежит так, что показать его
         второй раз нельзя. -->
    <div v-if="issued" class="alert warn">
      <b>{{ issued.title }}:</b>
      <code class="secret">{{ issued.value }}</code>
      <template v-if="issued.link">
        <br />Ссылка входа: <code class="secret">{{ issued.link }}</code>
      </template>
      <br />Передайте его сотруднику — показать второй раз не получится.
      <button class="btn small" type="button" style="margin-left: 8px" @click="issued = null">Скрыть</button>
    </div>

    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <p v-if="canEdit && !form">
        <button class="btn primary" type="button" @click="startCreate">Новый сотрудник</button>
      </p>

      <!-- Форма сотрудника -->
      <section v-if="form" class="panel">
        <h2>{{ form.id === null ? 'Новый сотрудник' : `Сотрудник «${form.login}»` }}</h2>
        <div v-if="formErr" class="alert">{{ formErr }}</div>

        <div class="form-grid">
          <label class="field">
            <span class="field-label">Логин</span>
            <input v-model="form.login" type="text" autocapitalize="off" spellcheck="false" />
          </label>
          <label class="field">
            <span class="field-label">ФИО</span>
            <input v-model="form.full_name" type="text" />
          </label>
          <label class="field">
            <span class="field-label">Должность</span>
            <input v-model="form.position" type="text" />
          </label>
          <label class="field">
            <span class="field-label">E-mail</span>
            <input v-model="form.email" type="email" />
          </label>
          <label class="field">
            <span class="field-label">Телефон</span>
            <input v-model="form.phone" type="text" />
          </label>
        </div>

        <p class="muted hint">
          Логин нечувствителен к регистру, пробелы по краям срезаются; русские буквы можно.
        </p>

        <template v-if="form.id === null">
          <div class="form-grid">
            <label class="field">
              <span class="field-label">Пароль</span>
              <input v-model="form.password" type="text" placeholder="например: ромашка кедр янтарь" />
            </label>
            <label class="field">
              <span class="field-label">Код подтверждения (необязательно)</span>
              <input v-model="form.confirm_code" type="text" placeholder="3–20 знаков" />
            </label>
          </div>
          <p class="muted hint">
            Пароль показан открытым нарочно: его нужно передать сотруднику. Код подтверждения
            спрашивают перед необратимыми действиями; сотрудник сможет сменить и то, и другое сам.
          </p>

          <p>
            <label class="check">
              <input v-model="form.fast_token_enabled" type="checkbox" />
              Выдать токен быстрого входа (действует только из рабочей сети)
            </label>
          </p>
        </template>

        <p>
          <label class="check">
            <input v-model="form.is_active" type="checkbox" />
            Учётная запись действует
          </label>
        </p>

        <div class="field">
          <span class="field-label">Роли</span>
          <div class="role-picker">
            <label v-for="role in roles" :key="role.id" class="check">
              <input type="checkbox" :checked="form.role_ids.includes(role.id)" @change="toggleRole(role.id)" />
              {{ role.name }}
              <span v-if="role.is_system" class="muted">— полный доступ</span>
            </label>
            <span v-if="!roles.length" class="muted">Ролей ещё нет — создайте их в разделе «Роли и права»</span>
          </div>
        </div>

        <p class="row-actions">
          <button class="btn primary" type="button" :disabled="saving || !form.login.trim()" @click="save">
            Сохранить
          </button>
          <button class="btn" type="button" @click="form = null">Отмена</button>
        </p>
      </section>

      <table class="pages-table">
        <thead>
          <tr>
            <th>Логин</th>
            <th>ФИО и должность</th>
            <th>Роли</th>
            <th>Секреты</th>
            <th>Последний вход</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in employees" :key="row.id">
            <td>
              <b>{{ row.login }}</b>
              <span v-if="row.id === me?.id" class="muted"> — это вы</span>
              <br />
              <span :class="row.is_active ? 'badge-on' : 'badge-off'">
                {{ row.is_active ? 'действует' : 'отключён' }}
              </span>
            </td>
            <td>
              {{ row.full_name || '—' }}
              <br /><span class="muted">{{ row.position || '' }}</span>
            </td>
            <td>
              <template v-if="row.roles.length">
                <span v-for="role in row.roles" :key="role.id" class="role-chip">{{ role.name }}</span>
              </template>
              <span v-else class="muted">нет ролей — доступа нет</span>
            </td>
            <td class="muted">
              {{ row.has_confirm_code ? 'код есть' : 'кода нет' }}<br />
              {{ row.has_fast_token ? 'токен есть' : 'токена нет' }}
            </td>
            <td>
              {{ stamp(row.last_login_at) }}
              <br /><span class="muted">{{ row.last_login_ip || '' }}</span>
            </td>
            <td class="pages-actions emp-actions">
              <button v-if="canEdit" class="btn small" type="button" @click="startEdit(row)">Править</button>
              <button v-if="canReset" class="btn small" type="button" @click="resetPassword(row)">Пароль</button>
              <button v-if="canReset" class="btn small" type="button" @click="resetCode(row)">Код</button>
              <button v-if="canReset" class="btn small" type="button" @click="issueToken(row)">Токен</button>
              <button v-if="canReset && row.has_fast_token" class="btn small" type="button" @click="dropToken(row)">
                Снять токен
              </button>
              <button v-if="canReset && row.sessions" class="btn small" type="button" @click="closeSessions(row)">
                Сессии ({{ row.sessions }})
              </button>
              <button
                v-if="canImpersonate && row.id !== me?.id && row.is_active"
                class="btn small"
                type="button"
                title="Увидеть АИС глазами этого сотрудника (только просмотр)"
                @click="impersonate(row)"
              >
                Войти как
              </button>
              <button v-if="canDelete && row.id !== me?.id" class="btn small" type="button" @click="remove(row)">
                Удалить
              </button>
            </td>
          </tr>
          <tr v-if="!employees.length">
            <td colspan="6" class="muted">Сотрудников пока нет</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>
