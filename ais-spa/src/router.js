import { createRouter, createWebHistory } from 'vue-router'
import BackTicketView from './views/BackTicketView.vue'
import BlocksView from './views/BlocksView.vue'
import ContactsView from './views/ContactsView.vue'
import CustomerView from './views/CustomerView.vue'
import CustomersView from './views/CustomersView.vue'
import DataSyncView from './views/DataSyncView.vue'
import EmployeesView from './views/EmployeesView.vue'
import FeedbackView from './views/FeedbackView.vue'
import HomeView from './views/HomeView.vue'
import KkmView from './views/KkmView.vue'
import MenuView from './views/MenuView.vue'
import NewsEditView from './views/NewsEditView.vue'
import NewsListView from './views/NewsListView.vue'
import PageEditView from './views/PageEditView.vue'
import PagesView from './views/PagesView.vue'
import ProfileView from './views/ProfileView.vue'
import ReportView from './views/ReportView.vue'
import RolesView from './views/RolesView.vue'
import SettingsView from './views/SettingsView.vue'
import SpamRulesView from './views/SpamRulesView.vue'
import StationsView from './views/StationsView.vue'

// Маршрутизация без # (history mode): / — список отчётов, /<slug> — отчёт.
// Админские страницы объявляем ДО catch-all, иначе их съест ReportView:
// /back-ticket — возврат билета (legacy back_bilet.php),
// /customers — покупатели сайта: кабинеты и покупавшие без регистрации
//   (в legacy такой страницы не было — АИС работала без авторизации),
// /contacts — контакты сайта (legacy avtovokzal57/url/contacts.php),
// /pages и /menu — страницы и меню сайта (legacy url/<slug>.php + index.php),
// /news — новости и объявления, /blocks — блоки сайта (legacy vidjets/*.php),
// /settings — выключатели продажи и возврата билетов,
// /stations — автостанции и подключения к их базам (в legacy — массив $AWT
//   в коде, в трёх местах), /kkm — настройки фискального сервера,
// /sync — немедленное обновление расписания и пунктов назначения,
// /feedback и /spam-rules — обращения с сайта и антиспам-словарь
// (legacy url/contact.php: письмо на почту и словарь в исходнике),
// /employees, /roles и /profile — сотрудники, роли и свой профиль
// (в legacy авторизации не было вовсе, переносить нечего).
//
// Прав маршруты не проверяют: это делает сервер на каждом запросе, а меню
// показывает только доступное (см. App.vue). Проверять в двух местах значит
// расходиться с сервером при первой же правке роли.
const routes = [
  { path: '/', component: HomeView },
  { path: '/back-ticket', component: BackTicketView },
  { path: '/customers', component: CustomersView },
  // Гость — это адрес, а не запись в базе, поэтому у него свой маршрут:
  // в адресах бывают точки и плюсы, и путать их с номером кабинета нельзя.
  { path: '/customers/guest/:email', component: CustomerView, props: true },
  { path: '/customers/:id', component: CustomerView, props: true },
  { path: '/contacts', component: ContactsView },
  { path: '/pages', component: PagesView },
  { path: '/pages/:id', component: PageEditView, props: true },
  { path: '/news', component: NewsListView },
  { path: '/news/:id', component: NewsEditView, props: true },
  { path: '/blocks', component: BlocksView },
  { path: '/settings', component: SettingsView },
  { path: '/stations', component: StationsView },
  { path: '/kkm', component: KkmView },
  { path: '/sync', component: DataSyncView },
  { path: '/menu', component: MenuView },
  { path: '/feedback', component: FeedbackView },
  { path: '/spam-rules', component: SpamRulesView },
  { path: '/employees', component: EmployeesView },
  { path: '/roles', component: RolesView },
  { path: '/profile', component: ProfileView },
  { path: '/:slug', component: ReportView, props: true },
]

const router = createRouter({
  history: createWebHistory('/'),
  routes,
})

// Заголовок вкладки; для отчёта его уточнит ReportView после загрузки meta.
router.afterEach((to) => {
  document.title = to.path === '/' ? 'АИС · Отчёты' : 'АИС'
})

export default router
