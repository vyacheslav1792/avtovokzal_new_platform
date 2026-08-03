<script setup>
/**
 * Визуальный режим редактора страниц сайта.
 *
 * Правим HTML напрямую в contenteditable внутри iframe. Iframe здесь не
 * прихоть: перенесённые из legacy страницы носят с собой собственные
 * <style> (`#pers{text-align:justify}`, полосатые блоки FAQ) — в общем
 * документе админки они разъехались бы по всему интерфейсу. Заодно текст
 * виден примерно так же, как на сайте.
 *
 * Редактор ничего не нормализует: div с классами, инлайновые стили и
 * комментарии legacy переживают переключение режимов — в отличие от
 * редакторов со своей схемой документа.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'pick-file'])

const frame = ref(null)
let doc = null
let savedRange = null
// Что редактор вписал в документ последним: без этого внешний watch
// перезаписывал бы содержимое на каждый набранный символ.
let lastEmitted = null

/** Типографика как на сайте — чтобы визуальный режим не врал о виде страницы. */
const BASE_STYLES = `
  body { margin: 0; padding: 16px 18px; font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; }
  h1 { font-size: 30px; line-height: 1.2; margin: 0 0 20px; }
  h2 { font-size: 24px; line-height: 1.3; margin: 28px 0 12px; }
  h3 { font-size: 20px; margin: 24px 0 8px; }
  p { margin: 0 0 14px; }
  ul, ol { margin: 0 0 16px 24px; }
  li { margin-bottom: 6px; }
  a { color: #2563eb; text-decoration: underline; }
  img { max-width: 100%; height: auto; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th, td { border: 1px solid #d1d5db; padding: 6px 9px; text-align: left; }
  /* Служебная подсветка структуры: на сайте её нет */
  [contenteditable] :is(div, section) { outline: 1px dashed transparent; }
  body:focus-within :is(table, td, th) { outline-color: #e5e7eb; }
`

function writeDocument() {
  const iframe = frame.value
  if (!iframe) return

  doc = iframe.contentDocument
  doc.open()
  doc.write(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>${BASE_STYLES}</style></head><body contenteditable="true"></body></html>`)
  doc.close()

  doc.body.innerHTML = props.modelValue || ''
  lastEmitted = doc.body.innerHTML

  doc.addEventListener('input', onInput)
  doc.addEventListener('selectionchange', rememberSelection)
  // Ссылки внутри редактора не должны уводить со страницы админки.
  doc.addEventListener('click', (e) => {
    const link = e.target.closest && e.target.closest('a')
    if (link) e.preventDefault()
  })
}

function onInput() {
  lastEmitted = doc.body.innerHTML
  emit('update:modelValue', lastEmitted)
}

/** Выделение запоминаем: клик по кнопке панели уводит фокус из iframe. */
function rememberSelection() {
  const selection = doc.getSelection()
  if (selection && selection.rangeCount) savedRange = selection.getRangeAt(0).cloneRange()
}

function restoreSelection() {
  frame.value.contentWindow.focus()
  const selection = doc.getSelection()

  if (savedRange) {
    selection.removeAllRanges()
    selection.addRange(savedRange)

    return
  }

  // По редактору ещё не кликали: без курсора execCommand молча ничего не
  // делает — ставим его в конец текста.
  if (selection.rangeCount === 0) {
    const range = doc.createRange()
    range.selectNodeContents(doc.body)
    range.collapse(false)
    selection.removeAllRanges()
    selection.addRange(range)
  }
}

function exec(command, value = null) {
  restoreSelection()
  doc.execCommand(command, false, value)
  onInput()
  rememberSelection()
}

function formatBlock(tag) {
  exec('formatBlock', `<${tag}>`)
}

function insertHtml(fragment) {
  exec('insertHTML', fragment)
}

function addLink() {
  const selection = doc.getSelection()
  const text = selection ? selection.toString() : ''
  const url = window.prompt('Адрес ссылки', 'https://')
  if (!url) return

  if (text) exec('createLink', url)
  else insertHtml(`<a href="${escapeAttr(url)}">${escapeHtml(url)}</a>`)
}

function insertTable() {
  const rows = Number(window.prompt('Сколько строк?', '3'))
  const cols = Number(window.prompt('Сколько столбцов?', '3'))
  if (!rows || !cols || rows < 1 || cols < 1) return

  const head = `<tr>${'<th>Заголовок</th>'.repeat(cols)}</tr>`
  const body = `<tr>${'<td>&nbsp;</td>'.repeat(cols)}</tr>`.repeat(Math.max(rows - 1, 1))
  insertHtml(`<table>${head}${body}</table><p>&nbsp;</p>`)
}

/** Строка/столбец добавляются к той ячейке, в которой стоит курсор. */
function currentCell() {
  const selection = doc.getSelection()
  const node = selection && selection.anchorNode
  const el = node && (node.nodeType === 1 ? node : node.parentElement)
  return el ? el.closest('td, th') : null
}

function addRow() {
  const cell = currentCell()
  if (!cell) return window.alert('Поставьте курсор в ячейку таблицы')

  const row = cell.parentElement
  const clone = doc.createElement('tr')
  for (let i = 0; i < row.children.length; i++) {
    const td = doc.createElement('td')
    td.innerHTML = '&nbsp;'
    clone.appendChild(td)
  }
  row.after(clone)
  onInput()
}

function addColumn() {
  const cell = currentCell()
  if (!cell) return window.alert('Поставьте курсор в ячейку таблицы')

  const index = Array.from(cell.parentElement.children).indexOf(cell)
  const table = cell.closest('table')
  for (const row of table.rows) {
    const isHead = row.children[index] && row.children[index].tagName === 'TH'
    const fresh = doc.createElement(isHead ? 'th' : 'td')
    fresh.innerHTML = '&nbsp;'
    row.children[index].after(fresh)
  }
  onInput()
}

function removeRow() {
  const cell = currentCell()
  if (!cell) return window.alert('Поставьте курсор в ячейку таблицы')

  const row = cell.parentElement
  const table = cell.closest('table')
  if (table.rows.length <= 1) table.remove()
  else row.remove()
  onInput()
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
}

function escapeAttr(value) {
  return escapeHtml(value).replace(/'/g, '&#39;')
}

/** Вставка файла, выбранного в менеджере: картинка — изображением, прочее — ссылкой. */
function insertFile(file) {
  const isImage = /\.(png|jpe?g|gif|webp|svg)$/i.test(file.url)

  if (isImage) insertHtml(`<img src="${escapeAttr(file.url)}" alt="">`)
  else insertHtml(`<a href="${escapeAttr(file.url)}" target="_blank">${escapeHtml(file.path.split('/').pop())}</a>`)
}

defineExpose({ insertFile })

onMounted(writeDocument)

onBeforeUnmount(() => {
  if (!doc) return
  doc.removeEventListener('input', onInput)
  doc.removeEventListener('selectionchange', rememberSelection)
})

// Содержимое пришло извне (переключились с HTML-режима или загрузили
// страницу) — перерисовываем документ.
watch(() => props.modelValue, (value) => {
  if (!doc || value === lastEmitted) return
  doc.body.innerHTML = value || ''
  lastEmitted = doc.body.innerHTML
})
</script>

<template>
  <div class="html-editor">
    <div class="he-toolbar">
      <button type="button" class="he-btn" title="Обычный абзац" @mousedown.prevent @click="formatBlock('p')">¶</button>
      <button type="button" class="he-btn" title="Заголовок 1" @mousedown.prevent @click="formatBlock('h1')">H1</button>
      <button type="button" class="he-btn" title="Заголовок 2" @mousedown.prevent @click="formatBlock('h2')">H2</button>
      <button type="button" class="he-btn" title="Заголовок 3" @mousedown.prevent @click="formatBlock('h3')">H3</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Полужирный" @mousedown.prevent @click="exec('bold')"><b>Ж</b></button>
      <button type="button" class="he-btn" title="Курсив" @mousedown.prevent @click="exec('italic')"><i>К</i></button>
      <button type="button" class="he-btn" title="Подчёркнутый" @mousedown.prevent @click="exec('underline')"><u>Ч</u></button>
      <button type="button" class="he-btn" title="Убрать форматирование" @mousedown.prevent @click="exec('removeFormat')">✕ф</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Маркированный список" @mousedown.prevent @click="exec('insertUnorderedList')">• —</button>
      <button type="button" class="he-btn" title="Нумерованный список" @mousedown.prevent @click="exec('insertOrderedList')">1. —</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Ссылка" @mousedown.prevent @click="addLink">Ссылка</button>
      <button type="button" class="he-btn" title="Файл или картинка с сервера" @mousedown.prevent @click="emit('pick-file')">Файл…</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Вставить таблицу" @mousedown.prevent @click="insertTable">Таблица</button>
      <button type="button" class="he-btn" title="Добавить строку" @mousedown.prevent @click="addRow">+стр</button>
      <button type="button" class="he-btn" title="Добавить столбец" @mousedown.prevent @click="addColumn">+стлб</button>
      <button type="button" class="he-btn" title="Удалить строку" @mousedown.prevent @click="removeRow">−стр</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Отменить" @mousedown.prevent @click="exec('undo')">↶</button>
      <button type="button" class="he-btn" title="Повторить" @mousedown.prevent @click="exec('redo')">↷</button>
    </div>

    <iframe ref="frame" class="he-frame" title="Содержимое страницы" />
  </div>
</template>
