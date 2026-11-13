<script setup lang="ts">
/**
 * Ввод номера телефона маской `+7 (###) ###-##-##` — как в форме входа и
 * регистрации соседнего проекта gotov_front.
 *
 * Галочка «Городской / зарубежный номер» маску СНИМАЕТ целиком: у городских
 * (8 4862 54-04-04) и зарубежных номеров другая длина и формат, и маска их
 * просто не даёт ввести. Backend принимает номер строкой (min:10, max:32) и
 * нормализует сам, поэтому наружу отдаём ровно то, что видит покупатель.
 */
// Атрибуты вызова (class, size, highlight) идут на само поле, а не на
// обёртку с галочкой.
defineOptions({ inheritAttrs: false })

const RU_MASK = '+7 (###) ###-##-##'

const model = defineModel<string>({ default: '' })

/** Маска снята: номер городской или зарубежный. */
const freeform = ref(false)

/**
 * Номер мог прийти из профиля — решаем за покупателя, подходит ли маска:
 * российский мобильный это 11 цифр, начинающихся с 7 или 8.
 */
function fitsRuMask(value: string): boolean {
  const digits = value.replace(/\D+/g, '')
  return digits === '' || (digits.length === 11 && (digits[0] === '7' || digits[0] === '8'))
}

/**
 * Пока покупатель поля не касался, номер в нём — подставленный (телефон
 * кабинета, восстановление формы), и под него можно переключать маску.
 * После первого фокуса так делать нельзя: незаконченный номер по пути
 * («+7 (910») маске, разумеется, не соответствует, и галочка взводилась бы
 * сама посреди набора.
 */
const touched = ref(false)

watch(model, (value) => {
  if (!touched.value) freeform.value = !fitsRuMask(value)
}, { immediate: true })

const mask = computed(() => (freeform.value ? null : RU_MASK))
</script>

<template>
  <div class="space-y-2">
    <MaskedInput
      v-model="model"
      :mask="mask"
      eager
      inputmode="tel"
      autocomplete="tel"
      :placeholder="freeform ? '8 4862 54-04-04' : '+7 (910) 000-00-00'"
      v-bind="$attrs"
      @focus="touched = true"
    />
    <UCheckbox
      :model-value="freeform"
      label="Городской / зарубежный номер"
      :ui="{ label: 'text-sm text-muted' }"
      @update:model-value="freeform = Boolean($event); touched = true"
    />
  </div>
</template>
