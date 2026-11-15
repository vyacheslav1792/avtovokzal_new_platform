<script setup lang="ts">
/**
 * Поле пароля с «глазком»: нажатие показывает введённое.
 *
 * Пароль вводят с телефона, где опечатка не видна вовсе, а подтверждение
 * приходится набирать вторым полем — без возможности посмотреть, что
 * набрано, человек просто не может понять, почему «пароли не совпадают».
 *
 * Атрибуты (class, autocomplete, highlight, слушатели) прокидываем во
 * вложенный UInput руками — поэтому `inheritAttrs: false`, иначе Vue
 * повесил бы их ещё и на корневой элемент и обработчики срабатывали бы
 * дважды (та же ловушка, что в MaskedInput).
 */
defineOptions({ inheritAttrs: false })

const model = defineModel<string>({ default: '' })

const visible = ref(false)
</script>

<template>
  <UInput
    v-model="model"
    :type="visible ? 'text' : 'password'"
    v-bind="$attrs"
  >
    <template #trailing>
      <!-- `tabindex="-1"` — кнопка не должна вставать между полем пароля
           и кнопкой отправки при переходах по Tab. -->
      <UButton
        color="neutral"
        variant="link"
        size="sm"
        tabindex="-1"
        :icon="visible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
        :aria-label="visible ? 'Скрыть пароль' : 'Показать пароль'"
        :aria-pressed="visible"
        @click="visible = !visible"
      />
    </template>
  </UInput>
</template>
