/**
 * Оформление модальных окон, которые на телефоне должны занимать весь экран.
 *
 * По умолчанию UModal на узком экране — это карточка с полями по краям:
 * длинный текст в ней превращается в колодец, а ряд из трёх кнопок
 * («Отменить заказ» / «Не сейчас» / «Перейти к оплате») ломается на две
 * строки и разъезжается. Поэтому ниже `sm` окно разворачивается во весь
 * экран, а кнопки идут столбиком во всю ширину — в обратном порядке,
 * чтобы главное действие оказалось сверху, под большим пальцем.
 *
 * Выше `sm` всё остаётся как в теме Nuxt UI.
 */
export const MOBILE_FULLSCREEN_MODAL = {
  // `sm:max-w-xl` вместо штатного `max-w-lg`: в 512px ряд из трёх кнопок
  // («Отменить заказ» / «Не сейчас» / «Перейти к оплате») не помещался и
  // вылезал за поля окна — первая кнопка уезжала левее текста.
  // `flex-wrap` в подвале — страховка на случай более длинных подписей.
  content: 'sm:max-w-xl'
    + ' max-sm:inset-0 max-sm:top-0 max-sm:left-0 max-sm:w-full max-sm:max-w-none'
    + ' max-sm:max-h-dvh max-sm:translate-x-0 max-sm:translate-y-0'
    + ' max-sm:rounded-none max-sm:ring-0',
  footer: 'gap-2 flex-wrap max-sm:flex-col-reverse max-sm:items-stretch sm:justify-end',
} as const

/** Кнопка в подвале такого окна: на телефоне во всю ширину и в одну строку. */
export const MODAL_BUTTON_CLASS = 'max-sm:w-full justify-center whitespace-nowrap'
