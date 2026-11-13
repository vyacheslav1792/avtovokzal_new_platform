<script setup lang="ts">
/**
 * UInput с маской ввода (библиотека maska, та же, что на соседнем проекте
 * gotov_front).
 *
 * **Ловушка:** директиву `v-maska` на UInput повесить нельзя — директива,
 * указанная на КОМПОНЕНТЕ, применяется к его корневому элементу, а у UInput
 * это обёртка `<div>`, а не сам `<input>`. Поэтому маску навешиваем вручную
 * на нативный input, который UInput отдаёт через `defineExpose({ inputRef })`.
 *
 * `mask: null` полностью снимает маску (поле становится обычным) — так
 * работает галочка «городской/зарубежный номер» в PhoneInput.
 */
import { Mask, MaskInput, type MaskaDetail } from 'maska'

// Атрибуты вызова (class, size, highlight, слушатели) прокидываем во
// вложенное поле руками — иначе Vue повесит их ещё и на корневой элемент,
// и обработчики сработали бы дважды.
defineOptions({ inheritAttrs: false })

const props = defineProps<{
  /** Шаблон маски maska (`#` — цифра) либо null, если маска не нужна. */
  mask: string | null
  /** Подставлять разделители сразу после цифры, а не перед следующей. */
  eager?: boolean
}>()

const emit = defineEmits<{ maska: [MaskaDetail] }>()

/** Значение поля — всегда в МАСКИРОВАННОМ виде (как видит покупатель). */
const model = defineModel<string>({ default: '' })

const input = useTemplateRef<{ inputRef: HTMLInputElement | null }>('input')
let masker: MaskInput | null = null

function maskOptions() {
  return {
    mask: props.mask!,
    eager: props.eager ?? false,
    onMaska: (detail: MaskaDetail) => emit('maska', detail),
  }
}

/** Привести уже введённое значение к новой маске (её могли переключить). */
function remask() {
  if (!props.mask) return
  const masked = new Mask({ mask: props.mask, eager: props.eager ?? false })
    .masked(model.value ?? '')
  if (masked !== model.value) model.value = masked
}

function attach() {
  const el = input.value?.inputRef
  if (!el) return

  if (!props.mask) {
    masker?.destroy()
    masker = null
    return
  }

  if (masker) masker.update(maskOptions())
  else masker = new MaskInput(el, maskOptions())

  remask()
}

onMounted(attach)
watch(() => [props.mask, props.eager], attach)
onBeforeUnmount(() => masker?.destroy())
</script>

<template>
  <UInput ref="input" v-model="model" v-bind="$attrs" />
</template>
