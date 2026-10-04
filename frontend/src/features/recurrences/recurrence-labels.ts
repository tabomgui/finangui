import type { Frequency, Recurrence } from '@/api/types'
import { formatDayMonth, parseDateOnly } from '@/lib/date'

export const FREQUENCY_LABELS: Record<Frequency, string> = {
  weekly: 'Toda semana',
  monthly: 'Todo mês',
  yearly: 'Todo ano',
}

/** Unidade do intervalo por frequência ("semanas"/"meses"/"anos") — ver `recurrence-schedule-fields.tsx`. */
export const INTERVAL_UNIT_LABELS: Record<Frequency, string> = {
  weekly: 'semanas',
  monthly: 'meses',
  yearly: 'anos',
}

/** Resumo em uma linha da frequência de uma recorrência, usado na lista e no formulário. */
export function frequencyLabel(recurrence: Recurrence): string {
  const { frequency, interval } = recurrence

  if (frequency === 'monthly') {
    const day = recurrence.day_of_month ?? parseDateOnly(recurrence.starts_on).getDate()
    return interval === 1 ? `Todo mês, dia ${day}` : `A cada ${interval} meses, dia ${day}`
  }

  if (frequency === 'weekly') {
    return interval === 1 ? 'Toda semana' : `A cada ${interval} semanas`
  }

  const date = formatDayMonth(recurrence.starts_on)
  return interval === 1 ? `Todo ano em ${date}` : `A cada ${interval} anos em ${date}`
}
