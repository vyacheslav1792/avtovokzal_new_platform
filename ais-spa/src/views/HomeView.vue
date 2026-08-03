<script setup>
import { ref, onMounted } from 'vue'
import { fetchReports } from '../api'

const reports = ref([])
const err = ref('')

onMounted(async () => {
  try {
    const d = await fetchReports()
    reports.value = d.reports || []
  } catch (e) {
    err.value = e.message
  }
})
</script>

<template>
  <div>
    <h1>Отчёты</h1>
    <p class="muted">Выберите отчёт. По мере переноса сюда добавятся остальные отчёты.</p>
    <div v-if="err" class="alert">{{ err }}</div>
    <div class="cards">
      <router-link v-for="r in reports" :key="r.slug" class="card" :to="`/${r.slug}`">
        <div class="card-title">{{ r.title }}</div>
        <div class="card-group" v-if="r.group">{{ r.group }}</div>
      </router-link>
    </div>
  </div>
</template>
