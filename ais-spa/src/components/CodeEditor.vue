<script setup>
// HTML-режим редактора страниц: CodeMirror 6 с подсветкой синтаксиса,
// нумерацией строк и автозакрытием тегов. Правится ровно тот же HTML,
// который лежит в базе и уходит на сайт (в legacy это был файл
// avtovokzal57/url/<slug>.php).
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { EditorView, basicSetup } from 'codemirror'
import { EditorState } from '@codemirror/state'
import { html } from '@codemirror/lang-html'

const props = defineProps({
  modelValue: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const host = ref(null)
let view = null

onMounted(() => {
  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        basicSetup,
        html(),
        EditorView.lineWrapping,
        EditorView.updateListener.of((update) => {
          if (update.docChanged) emit('update:modelValue', update.state.doc.toString())
        }),
      ],
    }),
  })
})

onBeforeUnmount(() => {
  if (view) view.destroy()
})

// Внешнее изменение (переключились из визуального режима) — переписываем
// документ целиком. Сравнение обязательно: без него каждый свой же ввод
// возвращался бы обратно и сбивал курсор.
watch(() => props.modelValue, (value) => {
  if (!view || value === view.state.doc.toString()) return
  view.dispatch({
    changes: { from: 0, to: view.state.doc.length, insert: value },
  })
})
</script>

<template>
  <div ref="host" class="code-editor" />
</template>
