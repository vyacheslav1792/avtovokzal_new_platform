/**
 * Тема Nuxt UI.
 *
 * `primary` из коробки зелёный — из-за этого ссылки и акценты на сайте были
 * зелёными, тогда как на боевом avtovokzal57 они красные (`a { color: #d0021b }`
 * в `css/main.css`). Палитра `brand` объявлена в `app/assets/css/main.css`
 * и построена вокруг этого же цвета.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'brand',
      // Красный остаётся и семантическим «ошибка/опасность» — кнопки покупки
      // в разметке используют color="error", и цвет у них тот же, что в legacy.
      error: 'brand',
    },
  },
})
