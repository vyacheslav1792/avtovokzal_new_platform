<script setup lang="ts">
/**
 * Ввод даты рождения маской `дд.мм.гггг` вместо `<input type="date">`.
 *
 * Нативный date-input на телефоне открывает календарь, а крутить его на
 * 50 лет назад неудобно — дату рождения набирают цифрами. Поведение
 * повторяет форму регистрации соседнего проекта gotov_front.
 *
 * Наружу (`v-model`) значение уходит в ISO `ГГГГ-ММ-ДД` — в таком виде его
 * ждёт backend (правило `date`). Пока дата не набрана целиком или не
 * существует (31.02), модель пустая, а `v-model:invalid` взводится, чтобы
 * страница показала подсказку под полем.
 */
import type { MaskaDetail } from 'maska'

// Атрибуты вызова (class, size, highlight, слушатели) прокидываем во
// вложенное поле руками — иначе Vue повесит их ещё и на корневой элемент,
// и обработчики сработали бы дважды.
defineOptions({ inheritAttrs: false })

const props = defineProps<{
  /**
   * Не раньше этой даты (ISO). По умолчанию — 100 лет назад: ровно так
   * считает вендорная процедура станции `KS_CONTROL_BIRTH_PAS`
   * (`birth_day >= today - 100 лет`), и билет с более ранней датой она не
   * оформит — отказ придёт уже после оплаты.
   */
  min?: string
  /** Не позже этой даты (ISO); по умолчанию — вчера (дата рождения в прошлом). */
  max?: string
}>()

/** ISO `ГГГГ-ММ-ДД` либо пустая строка. */
const model = defineModel<string>({ default: '' })
/** Дата набрана целиком, но такой даты не существует (или вне границ). */
const invalid = defineModel<boolean>('invalid', { default: false })

/** `1990-05-07` → `07.05.1990`. */
function isoToRu(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '')
  return m ? `${m[3]}.${m[2]}.${m[1]}` : ''
}

/** `07.05.1990` → `1990-05-07`; пусто, если такой даты не существует. */
function ruToIso(ru: string): string {
  const m = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(ru ?? '')
  if (!m) return ''

  const [day, month, year] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const date = new Date(Date.UTC(year, month - 1, day))

  // Date сам «исправляет» 31 февраля на 3 марта — сверяем, что не исправил.
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
    return ''
  }

  // Саму дату собираем из UTC-полей того же UTC-дня — здесь смещения нет.
  const iso = date.toISOString().slice(0, 10)
  const min = props.min ?? siteDateYearsAgo(100)
  // Верхняя граница — вчера по времени автовокзала, а не по UTC.
  const max = props.max ?? siteDateShifted(-1)

  return iso >= min && iso <= max ? iso : ''
}

/** То, что видит покупатель: `дд.мм.гггг`. */
const display = ref(isoToRu(model.value))

// Значение подставили извне (пассажир из профиля, сброс формы) — показываем.
watch(model, (iso) => {
  if (ruToIso(display.value) === iso) return
  display.value = isoToRu(iso)
  invalid.value = false
})

function onMaska(detail: MaskaDetail) {
  const iso = detail.completed ? ruToIso(detail.masked) : ''
  model.value = iso
  invalid.value = detail.completed && !iso
}
</script>

<template>
  <MaskedInput
    v-model="display"
    mask="##.##.####"
    placeholder="дд.мм.гггг"
    inputmode="numeric"
    autocomplete="bday"
    v-bind="$attrs"
    @maska="onMaska"
  />
</template>
