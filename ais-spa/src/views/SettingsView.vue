<script setup>
/**
 * Настройки сайта: продажа и возврат билетов, ожидание связи со станцией.
 *
 * В legacy выключателя не было вовсе — остановить продажу можно было только
 * правкой кода или снятием сайта целиком. При неполадках у станции это
 * означало, что покупатель платит за билет, который потом не оформится, и
 * деньги приходится возвращать вручную.
 *
 * Правка требует кода подтверждения (право settings.edit помечено как
 * опасное): выключенная продажа останавливает выручку сайта.
 *
 * Состав полей задаёт СЕРВЕР (каталог backend/config/settings.php): он
 * присылает тип, границы и подпись единицы, поэтому новая настройка
 * появляется здесь сама, а границы числа не приходится держать второй
 * копией.
 */
import { computed, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { fetchSettings, saveSettings } from '../siteApi'
import { can } from '../auth'

const settings = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const snapshot = ref('')

const dirty = computed(() => snapshot.value !== JSON.stringify(settings.value))

/**
 * Что из ОСТАНАВЛИВАЮЩЕГО сайт сейчас выключено — об этом стоит сказать
 * прямо наверху страницы. Признак приходит с сервера (`stops_site`):
 * настройки, которые просто меняют поведение, сюда попадать не должны —
 * иначе выключенный перенос даты возврата выглядел бы как остановленная
 * продажа, с текстом «покупатель видит объявление вместо формы».
 */
const disabled = computed(
  () => settings.value.filter((s) => s.stops_site && s.type === 'bool' && !s.value),
)

/**
 * Число зажимаем в границы сразу, не дожидаясь сохранения: сервер всё
 * равно сделает это у себя (SettingService::cast), но молча — и сотрудник
 * увидел бы «сохранено», а в поле другое значение.
 */
function clamp(setting) {
  if (setting.type !== 'int') return

  const value = Number(setting.value)

  if (!Number.isFinite(value)) {
    setting.value = setting.default
    return
  }

  let result = Math.round(value)

  if (setting.min != null) result = Math.max(setting.min, result)
  if (setting.max != null) result = Math.min(setting.max, result)

  setting.value = result
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    settings.value = await fetchSettings()
    snapshot.value = JSON.stringify(settings.value)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  saved.value = false
  settings.value.forEach(clamp)
  try {
    settings.value = await saveSettings(
      settings.value.map((s) => ({ key: s.key, value: s.value })),
    )
    snapshot.value = JSON.stringify(settings.value)
    saved.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

onBeforeRouteLeave(() => {
  if (!dirty.value) return true

  return window.confirm('Изменения не сохранены. Уйти со страницы?')
})

onMounted(() => {
  document.title = 'Настройки сайта · АИС'
  load()
})
</script>

<template>
  <div class="narrow">
    <h1>Настройки</h1>
    <p class="muted">
      Настройки сайта автовокзал57. Меняются сразу — ждать выкладки не нужно.
      Кассы, АИС и возврат билета через АИС переключатели продажи и возврата
      не затрагивают.
    </p>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-if="saved && !dirty" class="alert ok">Настройки сохранены и уже действуют.</div>
    <div v-if="loading" class="muted loading">Загрузка…</div>

    <template v-else>
      <div v-if="disabled.length" class="alert warn">
        Сейчас выключено:
        <b>{{ disabled.map((s) => s.title.toLowerCase()).join(', ') }}</b>.
        Покупатель видит на сайте объявление вместо формы.
      </div>

      <div v-for="setting in settings" :key="setting.key" class="panel setting-panel">
        <label v-if="setting.type === 'bool'" class="check setting-switch">
          <input v-model="setting.value" type="checkbox" :disabled="!can('settings.edit')">
          <span class="setting-title">{{ setting.title }}</span>
          <span :class="setting.value ? 'badge-on' : 'badge-off'">
            {{ setting.value ? 'включено' : 'выключено' }}
          </span>
        </label>

        <label v-else class="setting-number">
          <span class="setting-title">{{ setting.title }}</span>
          <span class="setting-number-row">
            <input
              v-model.number="setting.value"
              type="number"
              :min="setting.min ?? undefined"
              :max="setting.max ?? undefined"
              :disabled="!can('settings.edit')"
              @blur="clamp(setting)"
            >
            <span v-if="setting.unit">{{ setting.unit }}</span>
          </span>
          <span class="muted setting-range">
            допустимо {{ setting.min }}–{{ setting.max }}{{ setting.unit ? ' ' + setting.unit : '' }},
            по умолчанию {{ setting.default }}{{ setting.unit ? ' ' + setting.unit : '' }}
          </span>
        </label>

        <p class="hint setting-hint">{{ setting.hint }}</p>
      </div>

      <p class="edit-actions">
        <button v-if="can('settings.edit')" type="button" class="btn primary" :disabled="saving || !dirty" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
        <span v-if="dirty" class="muted">есть несохранённые изменения</span>
      </p>
    </template>
  </div>
</template>
