import { appToday, isDateOnly, parseDateOnly } from '@/lib/date'

const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/

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
