<script setup>
/**
 * Роли и права. Набор прав у модулей разный: у отчёта это просмотр, у
 * возврата билета — осмотр, возврат и починка, у страниц сайта — просмотр,
 * правка, удаление. Каталог приходит с сервера (shared/ais_permissions.php
 * плюс список отчётов), поэтому новый отчёт или модуль появляется здесь сам.
 *
 * Роль «Администратор» системная: ей доступно всё, включая модули, которых
 * ещё нет, и менять её нельзя.
 */
import { computed, onMounted, ref } from 'vue'
import { createRole, deleteRole, fetchRoles, updateRole } from '../api'
import { can } from '../auth'

const roles = ref([])
const catalog = ref([])
const loading = ref(false)
const error = ref('')
const notice = ref('')

const form = ref(null)
const formErr = ref('')
const saving = ref(false)

const canEdit = computed(() => can('roles.edit'))
const canDelete = computed(() => can('roles.delete'))

const total = computed(() =>
  catalog.value.reduce((sum, group) =>
    sum + group.modules.reduce((n, m) => n + m.permissions.length, 0), 0))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchRoles()
    roles.value = data.roles
    catalog.value = data.catalog
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function startCreate() {
  formErr.value = ''
  form.value = { id: null, name: '', description: '', permissions: [] }
}

function startEdit(role) {
  formErr.value = ''
  form.value = {
    id: role.id,
    name: role.name,
    description: role.description || '',
    permissions: [...role.permissions],
  }
}

function key(moduleKey, permission) {
  return `${moduleKey}.${permission}`
}

function has(moduleKey, permission) {
  return form.value.permissions.includes(key(moduleKey, permission))
}

function toggle(moduleKey, permission) {
  const k = key(moduleKey, permission)
  const at = form.value.permissions.indexOf(k)
  if (at === -1) form.value.permissions.push(k)
  else form.value.permissions.splice(at, 1)
}

/** Все права модуля сразу — иначе у отчётов пришлось бы щёлкать по одной. */
function toggleModule(module) {
  const all = module.permissions.map((p) => key(module.key, p.key))
  const every = all.every((k) => form.value.permissions.includes(k))

  form.value.permissions = every
    ? form.value.permissions.filter((k) => !all.includes(k))
    : [...new Set([...form.value.permissions, ...all])]
}

function moduleChecked(module) {
  return module.permissions.every((p) => has(module.key, p.key))
}

function toggleGroup(group) {
  const all = group.modules.flatMap((m) => m.permissions.map((p) => key(m.key, p.key)))
  const every = all.every((k) => form.value.permissions.includes(k))

  form.value.permissions = every
    ? form.value.permissions.filter((k) => !all.includes(k))
    : [...new Set([...form.value.permissions, ...all])]
}

async function save() {
  saving.value = true
  formErr.value = ''
  try {
    if (form.value.id === null) await createRole(form.value)
    else await updateRole(form.value.id, form.value)

    form.value = null
    notice.value = 'Сохранено'
    await load()
  } catch (e) {
    formErr.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove(role) {
  if (!window.confirm(`Удалить роль «${role.name}»?`)) return

  error.value = ''
  notice.value = ''
  try {
    await deleteRole(role.id)
    notice.value = `Роль «${role.name}» удалена.`
    await load()
  } catch (e) {
    error.value = e.message
  }
}

onMounted(() => {
  document.title = 'Роли и права · АИС'
  load()
})
</script>

<template>
  <div>
    <h1>Роли и права</h1>
    <p class="muted">
      Роль — набор прав по модулям. Сотруднику можно назначить несколько ролей:
      права складываются.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="notice" class="alert ok">{{ notice }}</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <p v-if="canEdit && !form">
        <button class="btn primary" type="button" @click="startCreate">Новая роль</button>
      </p>

      <section v-if="form" class="panel">
        <h2>{{ form.id === null ? 'Новая роль' : `Роль «${form.name}»` }}</h2>
        <div v-if="formErr" class="alert">{{ formErr }}</div>

        <div class="form-grid">
          <label class="field">
            <span class="field-label">Название</span>
            <input v-model="form.name" type="text" />
          </label>
          <label class="field field-wide">
            <span class="field-label">Пояснение</span>
            <input v-model="form.description" type="text" placeholder="кому эта роль и зачем" />
          </label>
        </div>

        <p class="muted hint">Отмечено прав: {{ form.permissions.length }} из {{ total }}</p>

        <div v-for="group in catalog" :key="group.group" class="perm-group">
          <div class="perm-group-head">
            <span class="perm-group-title">{{ group.group }}</span>
            <button class="btn small" type="button" @click="toggleGroup(group)">Все / ничего</button>
          </div>

          <div v-for="module in group.modules" :key="module.key" class="perm-module">
            <label class="check perm-module-title">
              <input type="checkbox" :checked="moduleChecked(module)" @change="toggleModule(module)" />
              <b>{{ module.title }}</b>
            </label>
            <div class="perm-list">
              <label v-for="perm in module.permissions" :key="perm.key" class="check">
                <input type="checkbox" :checked="has(module.key, perm.key)" @change="toggle(module.key, perm.key)" />
                {{ perm.title }}
                <!-- Такие действия сервер не выполнит без кода подтверждения
                     сотрудника — право само по себе их не разрешает. -->
                <span v-if="perm.confirm" class="perm-confirm" title="требует кода подтверждения">код</span>
              </label>
            </div>
          </div>
        </div>

        <p class="row-actions">
          <button class="btn primary" type="button" :disabled="saving || !form.name.trim()" @click="save">
            Сохранить
          </button>
          <button class="btn" type="button" @click="form = null">Отмена</button>
        </p>
      </section>

      <table class="pages-table">
        <thead>
          <tr>
            <th>Роль</th>
            <th>Пояснение</th>
            <th>Прав</th>
            <th>Сотрудников</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="role in roles" :key="role.id">
            <td>
              <b>{{ role.name }}</b>
              <br v-if="role.is_system" />
              <span v-if="role.is_system" class="badge-on">системная</span>
            </td>
            <td>{{ role.description || '—' }}</td>
            <td>{{ role.is_system ? 'все' : role.permissions.length }}</td>
            <td>{{ role.employees }}</td>
            <td class="pages-actions">
              <button v-if="canEdit && !role.is_system" class="btn small" type="button" @click="startEdit(role)">
                Править
              </button>
              <button v-if="canDelete && !role.is_system" class="btn small" type="button" @click="remove(role)">
                Удалить
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>
