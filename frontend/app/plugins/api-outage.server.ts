/**
 * Код ответа страницы, отрисованной во время обслуживания backend.
 *
 * Отдавать 200 нельзя: для поисковика это значит «сайт так и выглядит», и
 * страница с объявлением об обслуживании может попасть в выдачу. 503
 * говорит «вернись позже» и выдачу не портит, а человек при этом видит
 * нормальную страницу с объявлением, а не пустой экран.
 *
 * Статус ставим в `app:rendered`: к этому моменту отработали все запросы
 * страницы, а ответ ещё не отправлен. Из обработчиков $fetch этого не
 * сделать — там нет контекста запроса.
 */
import { setResponseHeader, setResponseStatus } from 'h3'

export default defineNuxtPlugin((nuxtApp) => {
  const outage = useApiOutage()

  nuxtApp.hook('app:rendered', (context) => {
    if (outage.value?.kind !== 'maintenance') return

    const event = context.ssrContext?.event
    if (!event) return

    setResponseStatus(event, 503)
    // Сколько ждать — backend сообщает в Retry-After; отдаём осторожную
    // минуту, точное значение знает только он.
    setResponseHeader(event, 'Retry-After', '60')
  })
})
