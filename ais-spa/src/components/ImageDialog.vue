<script setup>
/**
 * Вставка и правка картинки в визуальном редакторе.
 *
 * В legacy картинки в текст страницы попадали так: файл кладут по FTP в
 * avtovokzal57/img, потом руками пишут <img src='/img/…'> в PHP-файле
 * страницы. Форк админки давал загрузку через TinyMCE, но выбор шёл по
 * списку имён файлов — что именно за картинка, видно не было.
 *
 * Здесь три пути до картинки (загрузить с компьютера, взять из уже
 * загруженных, указать адрес) и сразу параметры вставки: подпись для
 * незрячих, ширина и обтекание. Параметры и предпросмотр общие для всех
 * трёх путей: картинку видно до вставки.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { fetchUploads } from '../siteApi'
import { can } from '../auth'
import { humanSize, isImageFile, isImageUrl, uploadImage } from '../images'

const props = defineProps({
  /** Правка уже вставленной картинки: {src, alt, width, align, link}. */
  image: { type: Object, default: null },
})

const emit = defineEmits(['close', 'apply', 'remove'])

const SITE_URL = (import.meta.env.VITE_SITE_URL || 'http://localhost:3000').replace(/\/+$/, '')

const editing = computed(() => Boolean(props.image))

const tab = ref(editing.value ? 'library' : 'upload')
const src = ref(props.image?.src || '')
const alt = ref(props.image?.alt || '')
const align = ref(props.image?.align || 'center')
const width = ref(props.image?.width || '')
const linkFull = ref(Boolean(props.image?.link))

const files = ref([])
const filter = ref('')
const loadingFiles = ref(false)
const uploading = ref(false)
const dragging = ref(false)
const error = ref('')
const fileInput = ref(null)

const images = computed(() => {
  const needle = filter.value.trim().toLowerCase()

  return files.value
    .filter((f) => isImageUrl(f.url))
    .filter((f) => !needle || f.path.toLowerCase().includes(needle))
})

/** Адрес для показа: относительный — с сайта, внешний — как есть. */
function preview(url) {
  return /^(https?:)?\/\//.test(url) ? url : `${SITE_URL}${url}`
}

const previewStyle = computed(() => {
  const style = { maxWidth: '100%' }

  if (width.value) style.width = width.value
  if (align.value === 'center') style.display = 'block'
  if (align.value === 'center') style.margin = '0 auto'
  if (align.value === 'left') style.float = 'left'
  if (align.value === 'right') style.float = 'right'

  return style
})

async function loadFiles() {
  loadingFiles.value = true
  error.value = ''
  try {
    files.value = (await fetchUploads()).files
  }
  catch (e) {
    error.value = e.message
  }
  finally {
    loadingFiles.value = false
  }
}

async function upload(list) {
  const file = Array.from(list || []).find(isImageFile)

  if (!file) {
    error.value = 'Выберите файл с картинкой (JPEG, PNG, GIF, WebP или SVG)'

    return
  }

  uploading.value = true
  error.value = ''
  try {
    // Уменьшение до разумного размера — в браузере (см. images.js):
    // снимок с телефона иначе ляжет на страницу в полные 4000 точек.
    src.value = (await uploadImage(file)).url
    // Список мог устареть — обновляем, чтобы картинка нашлась и во вкладке
    // «Из загруженных».
    files.value = []
  }
  catch (e) {
    error.value = e.message
  }
  finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function onDrop(event) {
  dragging.value = false
  upload(event.dataTransfer?.files)
}

function apply() {
  if (!src.value) {
    error.value = 'Сначала выберите картинку'

    return
  }

  emit('apply', {
    src: src.value,
    alt: alt.value.trim(),
    align: align.value,
    width: width.value,
    link: linkFull.value,
  })
}

// Вкладку «Из загруженных» открыли впервые — подтягиваем список.
watch([tab, () => files.value.length], () => {
  if (tab.value === 'library' && !files.value.length && !loadingFiles.value) loadFiles()
})

onMounted(() => {
  if (tab.value === 'library') loadFiles()
})
</script>

<template>
  <div class="fm-backdrop" @click.self="emit('close')">
    <div class="fm-dialog img-dialog">
      <div class="fm-head">
        <b>{{ editing ? 'Картинка' : 'Вставить картинку' }}</b>
        <button type="button" class="btn small" @click="emit('close')">Закрыть</button>
      </div>

      <div class="tabs img-tabs">
        <button type="button" class="tab" :class="{ active: tab === 'upload' }" @click="tab = 'upload'">
          С компьютера
        </button>
        <button type="button" class="tab" :class="{ active: tab === 'library' }" @click="tab = 'library'">
          Из загруженных
        </button>
        <button type="button" class="tab" :class="{ active: tab === 'url' }" @click="tab = 'url'">
          По адресу
        </button>
      </div>

      <div class="img-pane">
        <!-- Загрузка с компьютера -->
        <template v-if="tab === 'upload'">
          <div
            v-if="can('uploads.upload')"
            class="img-drop"
            :class="{ over: dragging }"
            @dragover.prevent="dragging = true"
            @dragleave="dragging = false"
            @drop.prevent="onDrop"
          >
            <p v-if="uploading" class="muted">Загрузка…</p>
            <template v-else>
              <p>Перетащите сюда файл картинки</p>
              <p class="muted">или</p>
              <label class="btn small primary">
                Выбрать файл
                <input ref="fileInput" type="file" accept="image/*" hidden @change="upload($event.target.files)">
              </label>
              <p class="hint img-drop-hint">
                Большие снимки уменьшаются до 1600&nbsp;точек по длинной стороне —
                страница с картинкой в полный размер открывалась бы заметно дольше.
              </p>
            </template>
          </div>
          <p v-else class="muted">Загружать файлы на сайт у вас нет прав — возьмите картинку из загруженных.</p>
        </template>

        <!-- Уже загруженные картинки -->
        <template v-else-if="tab === 'library'">
          <input v-model="filter" class="fm-filter img-filter" type="search" placeholder="Поиск по имени файла">

          <p v-if="loadingFiles" class="muted loading">Загрузка…</p>
          <p v-else-if="!images.length" class="muted">
            Картинок пока нет — загрузите первую на вкладке «С компьютера».
          </p>
          <div v-else class="img-grid">
            <button
              v-for="file in images"
              :key="file.path"
              type="button"
              class="img-card"
              :class="{ chosen: src === file.url }"
              :title="`${file.path} · ${humanSize(file.size)}`"
              @click="src = file.url"
            >
              <img :src="preview(file.url)" :alt="file.path" loading="lazy">
              <span class="img-card-name">{{ file.path.split('/').pop() }}</span>
            </button>
          </div>
        </template>

        <!-- Внешний адрес -->
        <template v-else>
          <label class="field">
            <span class="field-label">Адрес картинки</span>
            <input v-model="src" type="url" placeholder="https://…/banner.jpg" class="img-url">
          </label>
          <p class="hint">
            Картинка останется на чужом сайте: если её там удалят или переименуют,
            на странице будет пустое место. Надёжнее загрузить файл к себе.
          </p>
        </template>
      </div>

      <!-- Параметры вставки: общие для всех трёх путей -->
      <div class="img-settings">
        <div class="img-preview">
          <img v-if="src" :src="preview(src)" :style="previewStyle" alt="Предпросмотр картинки">
          <p v-else class="muted">Картинка не выбрана</p>
        </div>

        <div class="img-fields">
          <label class="field">
            <span class="field-label">Подпись для незрячих (alt)</span>
            <input v-model="alt" type="text" placeholder="Что на картинке">
          </label>

          <label class="field">
            <span class="field-label">Расположение</span>
            <select v-model="align">
              <option value="center">По центру, отдельной строкой</option>
              <option value="inline">В тексте</option>
              <option value="left">Слева, текст обтекает</option>
              <option value="right">Справа, текст обтекает</option>
            </select>
          </label>

          <label class="field">
            <span class="field-label">Ширина</span>
            <select v-model="width">
              <option value="">Как есть</option>
              <option value="100%">Во всю ширину текста</option>
              <option value="50%">Половина ширины</option>
              <option value="33%">Треть ширины</option>
              <option value="600px">600 точек</option>
              <option value="400px">400 точек</option>
              <option value="200px">200 точек</option>
            </select>
          </label>

          <label class="check img-check">
            <input v-model="linkFull" type="checkbox">
            открывать картинку целиком по клику
          </label>
        </div>
      </div>

      <div v-if="error" class="alert">{{ error }}</div>

      <div class="img-actions">
        <button type="button" class="btn primary" :disabled="!src || uploading" @click="apply">
          {{ editing ? 'Применить' : 'Вставить' }}
        </button>
        <button v-if="editing" type="button" class="btn" @click="emit('remove')">Удалить картинку</button>
        <button type="button" class="btn" @click="emit('close')">Отмена</button>
      </div>
    </div>
  </div>
</template>
