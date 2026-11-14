import { createApp } from 'vue'
import App from './App.vue'
import { bootstrap } from './auth'
import router from './router'
import './style.css'

createApp(App).use(router).mount('#app')

// Кто вошёл, выясняется у сервера: токен сессии лежит в httpOnly-cookie и
// из JS не читается. Пока ответа нет, приложение показывает «Загрузка…».
bootstrap()
