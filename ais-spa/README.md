# ais-spa — SPA отчётов АИС

SPA-версия отчётов АИС автовокзалов (Vue 3 + Vite). Перенесена в монорепозиторий
из `avtovokzal_ais_spa` (тот проект работал поверх legacy `apiv2` и считается
устаревшим). Работает поверх **ais-api** (Laravel, :8086) — контракт ответов
(`ok`, `reports`/`meta`/`sections`) сохранён, поэтому рендер не менялся.

## Возможности

- Роутинг без `#` (history mode): `/` — список отчётов, `/<slug>` — отчёт.
- Серверные фильтры формируются из `meta` отчёта.
- Секции-аккордеоны (изначально свёрнуты).
- Таблицы: множественная клиентская сортировка, клиентские фильтры по датам,
  закреплённые шапка и итоги, пересчёт итогов по отфильтрованным строкам.
- Сворачиваемое боковое меню.

## Разработка

В составе стека: `docker compose up -d ais_spa` → http://localhost:3001
(vite dev-сервер, `/api` проксируется на `ais_web`).

Локально без docker:

```bash
npm install
npm run dev      # /api проксируется на http://localhost:8086 (env AIS_API_PROXY)
npm run build    # сборка в dist/
```

## Структура

- `src/api.js` — обёртка над ais-api (`/api/v1/reports*`).
- `src/router.js` — маршруты (history mode, base `/`).
- `src/views/` — `HomeView` (список), `ReportView` (отчёт).
- `src/components/` — `FiltersForm`, `ReportSection` (аккордеон), `DataTable`
  (сортировка/фильтры/итоги).

Добавление нового отчёта — на стороне ais-api (класс отчёта +
`config/reports.php`); SPA рисует его тем же `ReportView` без изменений.
