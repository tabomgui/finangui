import { describe, expect, it } from 'vitest'
import { canLinkAsTransfer, mergeTagIds, orderForLink, selectionKind } from './bulk'

function leg(overrides: {
  id: number
  direction: 'in' | 'out'
  amount?: number
  account_id?: number
  transfer_id?: string | null
  currency?: string
  installment?: { plan_id: number; number: number; total: number } | null
  status?: 'posted' | 'projected' | 'pending'
  is_ignored?: boolean
}) {
  return { amount: 1000, account_id: 1, transfer_id: null, currency: 'BRL', installment: null, status: 'posted' as const, is_ignored: false, ...overrides }
}

describe('mergeTagIds', () => {
  it('adiciona sem duplicar', () => {
    expect(mergeTagIds([1, 2], 2)).toEqual([1, 2])
    expect(mergeTagIds([1], 3)).toEqual([1, 3])
  })
})

describe('selectionKind', () => {
  it('retorna "expense" quando todas as transações são saídas', () => {
    expect(selectionKind([{ direction: 'out' }, { direction: 'out' }])).toBe('expense')
  })

  it('retorna "income" quando todas as transações são entradas', () => {
    expect(selectionKind([{ direction: 'in' }, { direction: 'in' }])).toBe('income')
  })

  it('retorna null quando a seleção mistura entradas e saídas, inclusive pernas de transferência', () => {
    expect(selectionKind([{ direction: 'out' }, { direction: 'in' }])).toBeNull()
  })

  it('retorna null para seleção vazia', () => {
    expect(selectionKind([])).toBeNull()
  })
})

describe('canLinkAsTransfer', () => {
  it('true com direções opostas, mesmo valor e contas diferentes', () => {
    expect(canLinkAsTransfer([leg({ id: 1, direction: 'out', account_id: 1 }), leg({ id: 2, direction: 'in', account_id: 2 })])).toBe(
      true,
    )
  })

  it('false com menos ou mais de 2 selecionadas', () => {
    expect(canLinkAsTransfer([leg({ id: 1, direction: 'out' })])).toBe(false)
    expect(canLinkAsTransfer([leg({ id: 1, direction: 'out' }), leg({ id: 2, direction: 'in' }), leg({ id: 3, direction: 'in' })])).toBe(
      false,
    )
  })

  it('false com mesma direção, valores diferentes, mesma conta ou já ligada', () => {
    expect(canLinkAsTransfer([leg({ id: 1, direction: 'out' }), leg({ id: 2, direction: 'out', account_id: 2 })])).toBe(false)
    expect(canLinkAsTransfer([leg({ id: 1, direction: 'out', amount: 500 }), leg({ id: 2, direction: 'in', account_id: 2 })])).toBe(
      false,
    )
    expect(canLinkAsTransfer([leg({ id: 1, direction: 'out' }), leg({ id: 2, direction: 'in' })])).toBe(false)
    expect(
      canLinkAsTransfer([
        leg({ id: 1, direction: 'out', transfer_id: 'uuid-1' }),
        leg({ id: 2, direction: 'in', account_id: 2 }),
      ]),
    ).toBe(false)
  })

  it('false com moedas diferentes', () => {
    expect(
      canLinkAsTransfer([leg({ id: 1, direction: 'out', currency: 'USD' }), leg({ id: 2, direction: 'in', account_id: 2 })]),
    ).toBe(false)
  })

  it('false com parcela, projetada ou ignorada em qualquer das duas', () => {
    expect(
      canLinkAsTransfer([
        leg({ id: 1, direction: 'out', installment: { plan_id: 1, number: 1, total: 3 } }),
        leg({ id: 2, direction: 'in', account_id: 2 }),
      ]),
    ).toBe(false)
    expect(
      canLinkAsTransfer([leg({ id: 1, direction: 'out', status: 'projected' }), leg({ id: 2, direction: 'in', account_id: 2 })]),
    ).toBe(false)
    expect(
      canLinkAsTransfer([leg({ id: 1, direction: 'out' }), leg({ id: 2, direction: 'in', account_id: 2, is_ignored: true })]),
    ).toBe(false)
  })
})

describe('orderForLink', () => {
  it('identifica a perna de saída e a de entrada independente da ordem recebida', () => {
    const out = leg({ id: 1, direction: 'out' })
    const inLeg = leg({ id: 2, direction: 'in', account_id: 2 })

    expect(orderForLink([out, inLeg])).toEqual({ out, in: inLeg })
    expect(orderForLink([inLeg, out])).toEqual({ out, in: inLeg })
  })
})
