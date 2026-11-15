/**
 * Подготовка картинок перед загрузкой на сервер.
 *
 * Уменьшаем в браузере, а не на сервере: ни GD, ни Imagick в контейнере
 * backend нет, а снимок с телефона или скриншот из буфера — это 3–8 МБ и
 * 4000 пикселей по ширине. Такая картинка в тексте страницы грузится
 * секунды и ровно так же выглядит, как уменьшенная до 1600 px.
 *
 * Анимированные GIF и SVG не трогаем: canvas потерял бы анимацию, а
 * вектор — смысл.
 */

import { uploadFile } from './siteApi'

/** Картинки лежат отдельно от документов: /upload/… как в legacy-форке. */
export const IMAGE_DIR = 'upload'

/** Больше этой ширины/высоты картинка в тексте страницы не нужна. */
const MAX_SIDE = 1600

/** Мелкие файлы не перекодируем: выигрыша нет, артефакты есть. */
const KEEP_AS_IS_BYTES = 300 * 1024

const RESIZABLE = ['image/jpeg', 'image/png', 'image/webp']

export function isImageFile(file) {
  return typeof file?.type === 'string' && file.type.startsWith('image/')
}

/** Картинка по адресу файла — по расширению: список файлов типов не знает. */
export function isImageUrl(url) {
  return /\.(png|jpe?g|gif|webp|svg|avif|bmp)$/i.test(String(url || ''))
}

/**
 * Уменьшенная копия картинки либо исходный файл, если уменьшать нечего.
 * Любая осечка (не декодировалось, нет canvas) — возвращаем исходник:
 * загрузка картинки важнее экономии байтов.
 */
export async function prepareImage(file) {
  if (!RESIZABLE.includes(file.type)) return file

  try {
    const bitmap = await loadBitmap(file)
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height))

    // Уже небольшая и по размеру, и по весу — оставляем как есть.
    if (scale === 1 && file.size <= KEEP_AS_IS_BYTES) {
      bitmap.close?.()

      return file
    }

    const width = Math.round(bitmap.width * scale)
    const height = Math.round(bitmap.height * scale)

    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height)
    bitmap.close?.()

    // PNG уходит в WebP: прозрачность сохраняется, вес падает в разы.
    // JPEG остаётся JPEG — он и так везде открывается.
    const type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp'
    const blob = await toBlob(canvas, type)

    if (!blob || blob.size >= file.size) return file

    return new File([blob], renameTo(file.name, type), { type, lastModified: Date.now() })
  }
  catch {
    return file
  }
}

/** Подготовить и залить картинку на сайт; возвращает {path, url, size}. */
export async function uploadImage(file) {
  return uploadFile(await prepareImage(file), IMAGE_DIR)
}

function loadBitmap(file) {
  if (typeof createImageBitmap === 'function') return createImageBitmap(file)

  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const image = new Image()
    image.onload = () => {
      URL.revokeObjectURL(url)
      resolve(image)
    }
    image.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('Картинку не удалось прочитать'))
    }
    image.src = url
  })
}

function toBlob(canvas, type) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, 0.85))
}

function renameTo(name, type) {
  const base = name.replace(/\.[^.]+$/, '') || 'image'
  const ext = type === 'image/jpeg' ? 'jpg' : 'webp'

  return `${base}.${ext}`
}

/** Человеческий размер файла — он же нужен в списке файлов сайта. */
export function humanSize(bytes) {
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`

  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}
