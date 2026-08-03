<script setup>
import { reactive, watch } from 'vue'

const props = defineProps({
  filters: { type: Array, required: true },
  loading: { type: Boolean, default: false },
})
const emit = defineEmits(['submit'])

// Модель значений фильтров, инициализируется из default каждого поля.
const model = reactive({})

function initModel() {
  for (const f of props.filters) {
    if (!(f.name in model)) model[f.name] = f.default ?? ''
  }
}
watch(() => props.filters, initModel, { immediate: true })

function submit() {
  emit('submit', { ...model })
}
</script>

<template>
  <form class="filters" @submit.prevent="submit">
    <div class="filters-grid">
      <label v-for="f in filters" :key="f.name" class="field">
        <span class="field-label">{{ f.label }}</span>

        <select v-if="f.type === 'select'" v-model="model[f.name]">
          <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>

        <input v-else-if="f.type === 'date'" type="date" v-model="model[f.name]" />

        <input v-else type="text" v-model="model[f.name]" />
      </label>
    </div>
    <div class="filters-actions">
      <button type="submit" class="btn primary" :disabled="loading">
        {{ loading ? 'Формирование…' : 'Сформировать' }}
      </button>
    </div>
  </form>
</template>
