export interface CabinetRefund {
  amount: number
  penalty_percent: number
  status: string
  money_refunded: boolean
  created_at: string | null
}

export interface CabinetTicket {
  id: string
  serial: string | null
  number: string | null
  place: number
  tariff: string
  passenger: string
  price: number
  baggage: number
  price_bag: number
  total: number
  refund_code: string | null
  refund: CabinetRefund | null
  can_refund: boolean
  refund_deadline_passed: boolean
}

export interface CabinetOrder {
  id: string
  status: string
  status_label: string
  created_at: string | null
  from_name: string
  to_name: string
  trip_name: string | null
  carrier: string | null
  date_start: string
  time_start: string
  arrival_at: string | null
  total_price: number
  refunded_total: number
  back_code: string | null
  email: string
  phone: string | null
  is_upcoming: boolean
  departed: boolean
  payment_url: string | null
  tickets: CabinetTicket[]
}

export interface RefundPreview {
  from_name: string
  to_name: string
  trip_name: string
  date_start: string
  time_start: string
  place: string | number
  penalty_percent: number
  amount: number
  confirm_token: string
  confirm_ttl: number
}

export interface RefundResult {
  from_name: string
  to_name: string
  trip_name: string
  date_start: string
  time_start: string
  amount: number
  bank_error: string | null
}

/**
 * Возврат билета из кабинета: расчёт условий → подтверждение.
 * Правила и суммы считает backend — те же, что при возврате по коду.
 */
export function useTicketRefund(onDone: () => void | Promise<void>) {
  const api = useApi()

  const dialogOpen = ref(false)
  const step = ref<'loading' | 'preview' | 'done'>('loading')
  const preview = ref<RefundPreview | null>(null)
  const result = ref<RefundResult | null>(null)
  const loading = ref(false)
  const errorMessage = ref('')

  async function startRefund(order: CabinetOrder, ticket: CabinetTicket) {
    dialogOpen.value = true
    step.value = 'loading'
    errorMessage.value = ''
    preview.value = null
    result.value = null

    try {
      const resp = await api<{ success: boolean, data: RefundPreview }>('/cabinet/refunds/preview', {
        method: 'POST',
        body: { order_id: order.id, ticket_id: ticket.id },
      })
      preview.value = resp.data
      step.value = 'preview'
    }
    catch (e: any) {
      errorMessage.value = apiErrorMessage(e, 'Не удалось рассчитать условия возврата')
      step.value = 'done'
    }
  }

  async function confirmRefund() {
    if (!preview.value) return
    loading.value = true
    errorMessage.value = ''

    try {
      const resp = await api<{ success: boolean, data: RefundResult }>('/cabinet/refunds/confirm', {
        method: 'POST',
        body: { confirm_token: preview.value.confirm_token },
      })
      result.value = resp.data
      step.value = 'done'
      await onDone()
    }
    catch (e: any) {
      errorMessage.value = apiErrorMessage(e, 'Не удалось вернуть билет')
      step.value = 'done'
      await onDone()
    }
    finally {
      loading.value = false
      preview.value = null
    }
  }

  function closeDialog() {
    dialogOpen.value = false
    preview.value = null
    result.value = null
    errorMessage.value = ''
  }

  return { dialogOpen, step, preview, result, loading, errorMessage, startRefund, confirmRefund, closeDialog }
}
