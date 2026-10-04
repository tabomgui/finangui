import { describe, expect, it } from 'vitest'
import type { Recurrence } from '@/api/types'
import { frequencyLabel, intervalHint } from './recurrence-labels'

function recurrence(overrides: Partial<Recurrence>): Recurrence {
  return {
    id: 1,
    account_id: 1,
    category_id: null,
    description: 'Aluguel',
    amount: 150000,
    direction: 'out',
    frequency: 'monthly',
    interval: 1,
    day_of_month: 10,
    starts_on: '2026-01-10',
    ends_on: null,
    generated_until: null,
    match_pattern: null,
    is_active: true,
    ...overrides,
  }
}

describe('frequencyLabel', () => {
  it('mensal com intervalo 1: "Todo mês, dia N"', () => {
    expect(frequencyLabel(recurrence({ frequency: 'monthly', interval: 1, day_of_month: 10 }))).toBe('Todo mês, dia 10')
  })

  it('mensal com intervalo maior que 1: "A cada N meses, dia D"', () => {
    expect(frequencyLabel(recurrence({ frequency: 'monthly', interval: 2, day_of_month: 5 }))).toBe('A cada 2 meses, dia 5')
  })

  it('semanal com intervalo 1: "Toda semana"', () => {
    expect(frequencyLabel(recurrence({ frequency: 'weekly', interval: 1, day_of_month: null }))).toBe('Toda semana')
  })

  it('semanal com intervalo maior que 1: "A cada N semanas"', () => {
    expect(frequencyLabel(recurrence({ frequency: 'weekly', interval: 2, day_of_month: null }))).toBe('A cada 2 semanas')
  })

  it('anual com intervalo 1: "Todo ano em dd/mm"', () => {
    expect(
      frequencyLabel(recurrence({ frequency: 'yearly', interval: 1, day_of_month: null, starts_on: '2026-03-15' })),
    ).toBe('Todo ano em 15/03')
  })

  it('anual com intervalo maior que 1: "A cada N anos em dd/mm"', () => {
    expect(
      frequencyLabel(recurrence({ frequency: 'yearly', interval: 3, day_of_month: null, starts_on: '2026-03-15' })),
    ).toBe('A cada 3 anos em 15/03')
  })
})

describe('intervalHint', () => {
  it('usa singular para 1 e plural acima', () => {
    expect(intervalHint('monthly', 1)).toBe('A cada 1 mês.')
    expect(intervalHint('monthly', 3)).toBe('A cada 3 meses.')
    expect(intervalHint('weekly', 1)).toBe('A cada 1 semana.')
    expect(intervalHint('yearly', 2)).toBe('A cada 2 anos.')
  })
})
