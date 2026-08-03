<script setup>
import { ref } from 'vue'
import DataTable from './DataTable.vue'

const props = defineProps({
  section: { type: Object, required: true },
})

defineEmits(['open-link']) // проброс внутренних ссылок из DataTable наверх

// Изначально секции свёрнуты (по флагу collapsed из API, по умолчанию — свёрнуто).
const open = ref(props.section.collapsed === false)

function toggle() { open.value = !open.value }
</script>

<template>
  <section class="rsection">
    <header class="rsection-head" @click="toggle">
      <span class="chevron" :class="{ open }">▶</span>
      <span class="rsection-title">{{ section.title }}</span>
      <span class="rsection-count">{{ section.rows.length }}</span>
    </header>
    <div v-if="open" class="rsection-body">
      <DataTable
        :columns="section.columns"
        :rows="section.rows"
        :totals="section.totals"
        @open-link="$emit('open-link', $event)"
      />
    </div>
  </section>
</template>
