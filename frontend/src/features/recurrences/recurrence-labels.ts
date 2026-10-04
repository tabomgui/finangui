import { format } from 'date-fns'
import type { Recurrence } from '@/api/types'
import { parseDateOnly } from '@/lib/date'

export const FREQUENCY_LABELS: Record<Recurrence['frequency'], string> = {
  weekly: 'Toda semana',
  monthly: 'Todo mês',
  yearly: 'Todo ano',
}

/** Dia/mês ("15/03") de uma data "YYYY-MM-DD", sem o ano — usado na frequência anual. */
function dayMonth(value: string): string {
  return format(parseDateOnly(value), 'dd/MM')
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

  const date = dayMonth(recurrence.starts_on)
  return interval === 1 ? `Todo ano em ${date}` : `A cada ${interval} anos em ${date}`
}
