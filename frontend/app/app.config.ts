/**
 * Тема Nuxt UI.
 *
 * `primary` из коробки зелёный — из-за этого ссылки и акценты на сайте были
 * зелёными, тогда как на боевом avtovokzal57 они красные (`a { color: #d0021b }`
 * в `css/main.css`). Палитра `brand` объявлена в `app/assets/css/main.css`
 * и построена вокруг этого же цвета.
 *
 * Размер по умолчанию у полей и кнопок — `xl` вместо `md`: на `md` контролы
 * мелкие (32px), в них неудобно попадать на телефоне. `xl` даёт 42px при
 * шрифте 16px — ровно как у legacy (`.order-line-form .form-control` и
 * `.btn-primary`: `height: 42px; font-size: 16px`). Задаётся здесь, а не
 * классами на страницах: `defaultVariants` темы Nuxt UI перекрывается
 * app.config, поэтому `size` в разметке остался только там, где нужен
 * НЕ дефолтный размер — мелкие служебные кнопки (`sm`/`xs`).
 */
const big = { defaultVariants: { size: 'xl' as const } }

export default defineAppConfig({
  ui: {
    colors: {
      primary: 'brand',
      // Красный остаётся и семантическим «ошибка/опасность» — кнопки покупки
      // в разметке используют color="error", и цвет у них тот же, что в legacy.
      error: 'brand',
    },
    button: big,
    input: big,
    inputNumber: big,
    inputMenu: big,
    select: big,
    selectMenu: big,
    textarea: big,
    checkbox: big,
    radioGroup: big,
    formField: big,
  },
})
