import { describe, expect, it } from 'vitest'
import { dueLabel, isOverdue, STATUS_LABELS } from './statement-labels'

describe('dueLabel', () => {
  it('fala do vencimento em dias', () => {
    expect(dueLabel({ days_until_due: 14, status: 'open' })).toBe('Vence em 14 dias')
    expect(dueLabel({ days_until_due: 1, status: 'closed' })).toBe('Vence amanhã')
    expect(dueLabel({ days_until_due: 0, status: 'partial' })).toBe('Vence hoje')
    expect(dueLabel({ days_until_due: -1, status: 'closed' })).toBe('Venceu ontem')
    expect(dueLabel({ days_until_due: -5, status: 'closed' })).toBe('Venceu há 5 dias')
  })

  it('fatura paga não fala de vencimento', () => {
    expect(dueLabel({ days_until_due: -5, status: 'paid' })).toBe('Paga')
  })
})

describe('isOverdue', () => {
  it('só fatura com saldo devedor depois do vencimento', () => {
    expect(isOverdue({ days_until_due: -1, status: 'closed' })).toBe(true)
    expect(isOverdue({ days_until_due: -1, status: 'partial' })).toBe(true)
    expect(isOverdue({ days_until_due: -1, status: 'paid' })).toBe(false)
    expect(isOverdue({ days_until_due: 0, status: 'closed' })).toBe(false)
  })
})

it('tem rótulo para todo status', () => {
  expect(STATUS_LABELS).toEqual({ open: 'Aberta', closed: 'Fechada', partial: 'Paga em parte', paid: 'Paga' })
})
