import type { TopCategory } from '@/api/types'
import { appToday, isDateOnly, parseDateOnly } from '@/lib/date'

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

/**
 * `?dia=` só entra se for uma data de calendário real (rejeita "2026-02-30", por exemplo) e não
 * futura (o backend recusaria com 422); fora isso, quem chama cai no padrão (hoje). O dia do
 * saldo é sempre explícito e independente do mês (ver `dashboard-page.tsx`), então não há "dia
 * padrão" para um mês específico aqui.
 */
export function dayFromParam(value: string | null): string | undefined {
  if (!value || !isDateOnly(value)) return undefined
  try {
    parseDateOnly(value)
  } catch {
    return undefined
  }
  return value <= appToday() ? value : undefined
}
