<script setup>
/**
 * Визуальный режим редактора страниц, новостей и блоков сайта.
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
 *
 * Картинки: кнопка «Картинка», перетаскивание файла в текст и вставка из
 * буфера (Ctrl+V скриншота) — всё через один диалог с предпросмотром и
 * параметрами (ImageDialog). Клик по вставленной картинке выделяет её и
 * открывает те же параметры: в legacy размер и обтекание задавались
 * правкой атрибута style в PHP-файле страницы.
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import ImageDialog from './ImageDialog.vue'
import { isImageFile, uploadImage } from '../images'

/**
 * Адрес сайта. Нужен как <base> документа редактора: картинки и ссылки в
 * тексте относительные (`/upload/banner.jpg`, `/docs/ustav.pdf`), а админка
 * живёт на другом хосте — без base браузер искал бы их у себя и показывал
 * бы вместо картинки пустую рамку.
 */
const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const props = defineProps({
  modelValue: { type: String, default: '' },
  /**
   * Высота поля ввода. Краткому содержанию новости 60vh ни к чему — рядом
   * с ним ещё полтора десятка полей, и форма превращается в простыню.
   */
  height: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'pick-file'])

const frame = ref(null)
let doc = null
let savedRange = null
// Что редактор вписал в документ последним: без этого внешний watch
// перезаписывал бы содержимое на каждый набранный символ.
let lastEmitted = null

/** Диалог картинки: null — закрыт, объект — параметры правимой картинки. */
const imageDialog = ref(null)
const imageDialogOpen = ref(false)
/** Выделенная картинка (клик по ней) — для панели над редактором. */
const selected = shallowRef(null)
const uploading = ref(false)
const uploadError = ref('')

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
  /* Выделенная картинка и место, куда ляжет перетаскиваемый файл */
  img.he-picked { outline: 3px solid #2563eb; outline-offset: 2px; }
  body.he-dragover { background: #f0f7ff; }
`

function writeDocument() {
  const iframe = frame.value
  if (!iframe) return

  doc = iframe.contentDocument
  doc.open()
  doc.write(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><base href="${SITE_URL}/"><style>${BASE_STYLES}</style></head><body contenteditable="true"></body></html>`)
  doc.close()

  doc.body.innerHTML = props.modelValue || ''
  lastEmitted = doc.body.innerHTML

  doc.addEventListener('input', onInput)
  doc.addEventListener('selectionchange', rememberSelection)
  doc.addEventListener('click', onClick)
  doc.addEventListener('dblclick', onDoubleClick)
  doc.addEventListener('paste', onPaste)
  doc.addEventListener('dragover', onDragOver)
  doc.addEventListener('dragleave', onDragLeave)
  doc.addEventListener('drop', onDrop)
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

/* ── Картинки ──────────────────────────────────────────────────────────── */

/**
 * Разметка картинки. Оформление — инлайновые стили, как в legacy-страницах:
 * классов у нового сайта нет, а правила «во всю ширину» и «обтекание»
 * должны работать и в письме, и в печатной форме.
 *
 * Обтекание и центрирование вешаем на ВНЕШНИЙ элемент: если картинка
 * обёрнута ссылкой, `margin: auto` у самой картинки ничего не центрирует —
 * центрировать нужно строчную ссылку.
 */
function imageHtml({ src, alt, align, width, link }) {
  const layout = {
    center: 'display:block;margin:12px auto;text-align:center',
    left: 'float:left;margin:0 16px 10px 0',
    right: 'float:right;margin:0 0 10px 16px',
    inline: '',
  }[align] || ''

  const size = `max-width:100%${width ? `;width:${width}` : ''}`

  if (link) {
    return `<a href="${escapeAttr(src)}" target="_blank" rel="noopener"${layout ? ` style="${layout}"` : ''}>`
      + `<img src="${escapeAttr(src)}" alt="${escapeAttr(alt)}" style="${size}"></a>`
  }

  return `<img src="${escapeAttr(src)}" alt="${escapeAttr(alt)}" style="${[layout, size].filter(Boolean).join(';')}">`
}

/** Что уже задано у картинки — чтобы диалог открылся с её параметрами. */
function imageParams(img) {
  const wrap = imageWrapper(img)
  const style = `${wrap.getAttribute('style') || ''};${img.getAttribute('style') || ''}`

  let align = 'inline'
  if (/float\s*:\s*left/i.test(style)) align = 'left'
  else if (/float\s*:\s*right/i.test(style)) align = 'right'
  else if (/margin\s*:[^;]*auto/i.test(style) || /text-align\s*:\s*center/i.test(style)) align = 'center'

  return {
    src: img.getAttribute('src') || '',
    alt: img.getAttribute('alt') || '',
    align,
    width: (img.style.width || '').trim(),
    link: wrap !== img,
  }
}

/** Ссылка-обёртка вокруг картинки, если она есть, иначе сама картинка. */
function imageWrapper(img) {
  const parent = img.parentElement

  return parent && parent.tagName === 'A' && parent.childElementCount === 1 && !parent.textContent.trim()
    ? parent
    : img
}

function openImageDialog(params = null) {
  imageDialog.value = params
  imageDialogOpen.value = true
}

function closeImageDialog() {
  imageDialogOpen.value = false
  imageDialog.value = null
}

function applyImage(params) {
  const target = selected.value

  if (imageDialog.value && target) {
    // Правка: меняем элемент целиком — так за один раз переживают и
    // появление ссылки-обёртки, и смена обтекания.
    const wrap = imageWrapper(target)
    target.classList.remove('he-picked')
    wrap.outerHTML = imageHtml(params)
    selected.value = null
    onInput()
  }
  else {
    insertHtml(imageHtml(params))
  }

  closeImageDialog()
}

function removeSelectedImage() {
  const target = selected.value
  if (!target) return closeImageDialog()

  imageWrapper(target).remove()
  selected.value = null
  onInput()
  closeImageDialog()
}

function onClick(event) {
  // Ссылки внутри редактора не должны уводить со страницы админки.
  const link = event.target.closest && event.target.closest('a')
  if (link) event.preventDefault()

  pick(event.target.tagName === 'IMG' ? event.target : null)
}

function onDoubleClick(event) {
  if (event.target.tagName !== 'IMG') return

  pick(event.target)
  openImageDialog(imageParams(event.target))
}

function pick(img) {
  if (selected.value && selected.value !== img) selected.value.classList.remove('he-picked')

  selected.value = img
  if (img) img.classList.add('he-picked')
}

/** Загрузка картинки из буфера или перетаскиванием: один путь на оба случая. */
async function insertUploadedImage(file, range = null) {
  uploadError.value = ''
  uploading.value = true
  try {
    const saved = await uploadImage(file)

    if (range) {
      savedRange = range
    }

    insertHtml(imageHtml({ src: saved.url, alt: '', align: 'center', width: '', link: false }))
  }
  catch (e) {
    uploadError.value = e.message
  }
  finally {
    uploading.value = false
  }
}

function onPaste(event) {
  const file = Array.from(event.clipboardData?.files || []).find(isImageFile)
  if (!file) return

  // Картинка из буфера — это данные, а не файл на диске: без загрузки
  // браузер вставил бы её как base64 прямо в текст страницы, и страница
  // весила бы мегабайты.
  event.preventDefault()
  rememberSelection()
  insertUploadedImage(file)
}

function onDragOver(event) {
  if (!Array.from(event.dataTransfer?.items || []).some((i) => i.kind === 'file')) return

  event.preventDefault()
  doc.body.classList.add('he-dragover')
}

function onDragLeave() {
  doc.body.classList.remove('he-dragover')
}

function onDrop(event) {
  const file = Array.from(event.dataTransfer?.files || []).find(isImageFile)
  doc.body.classList.remove('he-dragover')
  if (!file) return

  event.preventDefault()

  // Курсор ставим туда, куда отпустили файл, — иначе картинка уедет в то
  // место, где курсор стоял до перетаскивания.
  const range = doc.caretRangeFromPoint
    ? doc.caretRangeFromPoint(event.clientX, event.clientY)
    : null

  insertUploadedImage(file, range)
}

/** Вставка файла, выбранного в менеджере: картинка — изображением, прочее — ссылкой. */
function insertFile(file) {
  const isImage = /\.(png|jpe?g|gif|webp|svg)$/i.test(file.url)

  if (isImage) insertHtml(imageHtml({ src: file.url, alt: '', align: 'center', width: '', link: false }))
  else insertHtml(`<a href="${escapeAttr(file.url)}" target="_blank">${escapeHtml(file.path.split('/').pop())}</a>`)
}

defineExpose({ insertFile })

onMounted(writeDocument)

onBeforeUnmount(() => {
  if (!doc) return
  doc.removeEventListener('input', onInput)
  doc.removeEventListener('selectionchange', rememberSelection)
  doc.removeEventListener('click', onClick)
  doc.removeEventListener('dblclick', onDoubleClick)
  doc.removeEventListener('paste', onPaste)
  doc.removeEventListener('dragover', onDragOver)
  doc.removeEventListener('dragleave', onDragLeave)
  doc.removeEventListener('drop', onDrop)
})

// Содержимое пришло извне (переключились с HTML-режима или загрузили
// страницу) — перерисовываем документ.
watch(() => props.modelValue, (value) => {
  if (!doc || value === lastEmitted) return
  doc.body.innerHTML = value || ''
  lastEmitted = doc.body.innerHTML
  // Прежняя выделенная картинка в новом документе уже не существует.
  selected.value = null
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
      <button
        type="button"
        class="he-btn he-btn-accent"
        title="Вставить картинку: загрузить, выбрать из загруженных или указать адрес"
        @mousedown.prevent
        @click="openImageDialog()"
      >🖼 Картинка</button>
      <button type="button" class="he-btn" title="Документ с сервера (PDF и прочие файлы)" @mousedown.prevent @click="emit('pick-file')">Файл…</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Вставить таблицу" @mousedown.prevent @click="insertTable">Таблица</button>
      <button type="button" class="he-btn" title="Добавить строку" @mousedown.prevent @click="addRow">+стр</button>
      <button type="button" class="he-btn" title="Добавить столбец" @mousedown.prevent @click="addColumn">+стлб</button>
      <button type="button" class="he-btn" title="Удалить строку" @mousedown.prevent @click="removeRow">−стр</button>
      <span class="he-sep" />
      <button type="button" class="he-btn" title="Отменить" @mousedown.prevent @click="exec('undo')">↶</button>
      <button type="button" class="he-btn" title="Повторить" @mousedown.prevent @click="exec('redo')">↷</button>
    </div>

    <!-- Что делать с выделенной картинкой: иначе про двойной клик никто не узнает -->
    <div v-if="selected" class="he-strip">
      <span>Картинка выделена.</span>
      <button type="button" class="btn small primary" @mousedown.prevent @click="openImageDialog(imageParams(selected))">
        Размер и расположение
      </button>
      <button type="button" class="btn small" @mousedown.prevent @click="removeSelectedImage">Удалить</button>
      <span class="muted">Можно и двойным щелчком по картинке.</span>
    </div>

    <div v-if="uploading" class="he-strip">Картинка загружается…</div>
    <div v-if="uploadError" class="he-strip he-strip-error">
      {{ uploadError }}
      <button type="button" class="btn small" @click="uploadError = ''">Понятно</button>
    </div>

    <iframe
      ref="frame"
      class="he-frame"
      :style="height ? { height, minHeight: height } : null"
      title="Содержимое страницы"
    />

    <p class="he-foot muted">
      Картинку можно перетащить в текст прямо из папки или вставить из буфера (Ctrl+V).
    </p>

    <ImageDialog
      v-if="imageDialogOpen"
      :image="imageDialog"
      @close="closeImageDialog"
      @apply="applyImage"
      @remove="removeSelectedImage"
    />
  </div>
</template>
