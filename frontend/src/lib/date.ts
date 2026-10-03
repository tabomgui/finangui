import { addDays, addMonths, endOfMonth, format } from 'date-fns'
import { ptBR } from 'date-fns/locale'

// A API trabalha com datas sem hora ("YYYY-MM-DD"). Interpretar sempre em horário local:
// `new Date('2026-10-01')` seria UTC e mostraria o dia anterior no Brasil.

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

/** Só confere o formato "YYYY-MM-DD" (sem validar o calendário; para isso, parseDateOnly). */
export function isDateOnly(value: string): boolean {
  return DATE_ONLY.test(value)
}

function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1)
}

export function parseDateOnly(value: string): Date {
  if (!isDateOnly(value)) throw new Error(`Data inválida: "${value}"`)
  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(year, month - 1, day)
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
    throw new Error(`Data inválida: "${value}"`)
  }
  return date
}

export function toDateOnly(date: Date): string {
  return format(date, 'yyyy-MM-dd')
}

export function today(): string {
  return toDateOnly(new Date())
}

export function formatDate(value: string): string {
  return format(parseDateOnly(value), 'dd/MM/yyyy')
}

export function formatDayLabel(value: string, reference: string = today()): string {
  if (value === reference) return 'Hoje'
  if (value === toDateOnly(addDays(parseDateOnly(reference), -1))) return 'Ontem'
  return capitalize(format(parseDateOnly(value), "EEEEEE, dd 'de' MMM", { locale: ptBR }))
}

export function monthKey(date: Date): string {
  return format(date, 'yyyy-MM')
}

export function shiftMonth(key: string, delta: number): string {
  return monthKey(addMonths(parseDateOnly(`${key}-01`), delta))
}

export function formatMonth(key: string): string {
  return capitalize(format(parseDateOnly(`${key}-01`), "MMMM 'de' yyyy", { locale: ptBR }))
}

/** Primeiro e último dia do mês ("YYYY-MM"), para filtrar transações por período. */
export function monthRange(key: string): { from: string; to: string } {
  const start = parseDateOnly(`${key}-01`)
  return { from: toDateOnly(start), to: toDateOnly(endOfMonth(start)) }
}
