import type { CardStatement, StatementStatus } from '@/api/types'

type DueInfo = Pick<CardStatement, 'days_until_due' | 'status'>

export const STATUS_LABELS: Record<StatementStatus, string> = {
  open: 'Aberta',
  closed: 'Fechada',
  partial: 'Paga em parte',
  paid: 'Paga',
}

export function isOverdue({ days_until_due, status }: DueInfo): boolean {
  return days_until_due < 0 && (status === 'closed' || status === 'partial')
}

export function dueLabel({ days_until_due: days, status }: DueInfo): string {
  if (status === 'paid') return 'Paga'
  if (days > 1) return `Vence em ${days} dias`
  if (days === 1) return 'Vence amanhã'
  if (days === 0) return 'Vence hoje'
  if (days === -1) return 'Venceu ontem'
  return `Venceu há ${-days} dias`
}
