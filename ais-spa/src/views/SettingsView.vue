<script setup>
/**
 * Настройки сайта: продажа и возврат билетов.
 *
 * В legacy выключателя не было вовсе — остановить продажу можно было только
 * правкой кода или снятием сайта целиком. При неполадках у станции это
 * означало, что покупатель платит за билет, который потом не оформится, и
 * деньги приходится возвращать вручную.
 *
 * Правка требует кода подтверждения (право settings.edit помечено как
 * опасное): выключенная продажа останавливает выручку сайта.
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

/** Что сейчас выключено — об этом стоит сказать прямо наверху страницы. */
const disabled = computed(() => settings.value.filter((s) => s.type === 'bool' && !s.value))

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
      Выключатели сайта автовокзал57. Меняются сразу — ждать выкладки не нужно.
      Кассы, АИС и возврат билета через АИС эти переключатели не затрагивают.
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
        <label class="check setting-switch">
          <input v-model="setting.value" type="checkbox" :disabled="!can('settings.edit')">
          <span class="setting-title">{{ setting.title }}</span>
          <span :class="setting.value ? 'badge-on' : 'badge-off'">
            {{ setting.value ? 'включено' : 'выключено' }}
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
