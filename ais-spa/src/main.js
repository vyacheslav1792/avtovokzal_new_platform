import { createApp } from 'vue'
import App from './App.vue'
import { bootstrap } from './auth'
import router from './router'
import { startVersionWatch } from './version'
import './style.css'

createApp(App).use(router).mount('#app')

// Следим за выходом новой сборки: иначе сотрудник неделями работает в
// старой версии АИС (подробности — в src/version.js).
startVersionWatch(router)

// Кто вошёл, выясняется у сервера: токен сессии лежит в httpOnly-cookie и
// из JS не читается. Пока ответа нет, приложение показывает «Загрузка…».
bootstrap()
