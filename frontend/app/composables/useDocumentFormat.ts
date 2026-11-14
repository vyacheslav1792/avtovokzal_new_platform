/**
 * Форматы серии и номера документа — общие для формы покупки и для
 * пассажиров кабинета: обе формы вводят одно и то же, и расходиться им
 * нельзя (иначе в профиль ляжет документ, с которым покупка не пройдёт).
 *
 * Правила сняты с вендорной процедуры `KS_CONTROL_DATA_PAS` — их же
 * проверяет backend (`App\Services\PassengerDocument`). Формат задан не у
 * всех документов: у остальных станция серию с номером не проверяет, и
 * выдумывать подсказку нельзя — покупатель введёт по ней то, чего в
 * документе нет.
 */
interface DocumentFormat {
  hint: string
  placeholder: string
  /** Маска ввода (`#` — цифра) либо null: формат слишком свободный. */
  mask: string | null
}

const DOC_FORMATS: Record<number, DocumentFormat> = {
  21: { hint: '4 цифры серии и 6 цифр номера', placeholder: '4444 555555', mask: '#### ######' },
  22: { hint: '2 цифры серии и 7 цифр номера', placeholder: '75 1234567', mask: null },
  3: { hint: 'Римские цифры, две русские буквы и 6 цифр номера', placeholder: 'IV-АБ 123456', mask: null },
  15: { hint: 'Две русские буквы и 7 цифр номера', placeholder: 'АБ 1234567', mask: null },
  26: { hint: 'Две римские цифры и 7 цифр номера', placeholder: 'XV 1234567', mask: null },
}

export function docHint(docType: number): string {
  return DOC_FORMATS[docType]?.hint ?? ''
}

/** Пусто — формат документа станцией не задан, подсказывать нечего. */
export function docPlaceholder(docType: number): string {
  return DOC_FORMATS[docType]?.placeholder ?? ''
}

/**
 * Маска есть только у паспорта РФ: у остальных документов в серии бывают
 * буквы и римские цифры, и жёсткий шаблон мешал бы вводу.
 */
export function docMask(docType: number): string | null {
  return DOC_FORMATS[docType]?.mask ?? null
}
