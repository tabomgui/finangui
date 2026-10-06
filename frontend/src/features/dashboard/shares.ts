import { endOfMonth } from 'date-fns'
import type { TopCategory } from '@/api/types'
import { isDateOnly, parseDateOnly, toDateOnly } from '@/lib/date'

const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/

export type CategoryShare = TopCategory & { barPercent: number; expensePercent: number }

/** Proporções só para desenhar as barras; os valores vêm prontos da API. */
export function categoryShares(categories: TopCategory[], expense: number): CategoryShare[] {
  const max = Math.max(0, ...categories.map((category) => category.amount))
  return categories.map((category) => ({
    ...category,
    barPercent: max > 0 ? Math.round((category.amount / max) * 100) : 0,
    expensePercent: expense > 0 ? Math.round((category.amount / expense) * 100) : 0,
  }))
}

export function monthFromParam(value: string | null, fallback: string): string {
  return value && MONTH.test(value) ? value : fallback
}

/** `?dia=` só entra se já estiver no formato "YYYY-MM-DD"; sem isso, o backend decide o padrão. */
export function dayFromParam(value: string | null): string | undefined {
  return value && isDateOnly(value) ? value : undefined
}

/**
 * Mesma regra do backend pra decidir o dia do saldo quando a URL não tem `?dia` (mês corrente ou
 * futuro usa hoje, mês passado usa o fim do mês) — só pra saber quando dá pra tirar `?dia` da URL
 * sem mudar o dia efetivo. O saldo mostrado em si sempre vem do backend (`balance_date`).
 */
export function defaultBalanceDay(month: string, todayValue: string): string {
  const currentMonth = todayValue.slice(0, 7)
  if (month < currentMonth) return toDateOnly(endOfMonth(parseDateOnly(`${month}-01`)))
  return todayValue
}
