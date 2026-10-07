import { describe, expect, it } from 'vitest'
import type { Transaction } from '@/api/types'
import { transactionSubtitle } from './transaction-subtitle'

function transaction(overrides: Partial<Transaction>): Transaction {
  return {
    id: 1,
    account_id: 1,
    date: '2026-01-01',
    amount: 1000,
    direction: 'out',
    currency: 'BRL',
    description: 'Lançamento',
    original_description: 'Lançamento',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    category: null,
    tags: [],
    status: 'posted',
    source: 'manual',
    categorized_by: null,
    categorization: null,
    is_ignored: false,
    transfer_id: null,
    statement_id: null,
    is_card_payment: false,
    installment: null,
    ...overrides,
  }
}

describe('transactionSubtitle', () => {
  it('transferência', () => {
    expect(
      transactionSubtitle(
        transaction({ transfer_id: 'uuid', account: { id: 1, name: 'Inter', type: 'checking', color: null, icon: null } }),
      ),
    ).toBe('Transferência · Inter')
  })

  it('com categoria', () => {
    expect(
      transactionSubtitle(
        transaction({
          category: { id: 1, parent_id: null, name: 'Mercado', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
          account: { id: 2, name: 'Nubank', type: 'credit_card', color: null, icon: null },
        }),
      ),
    ).toBe('Mercado · Nubank')
  })

  it('sem categoria', () => {
    expect(
      transactionSubtitle(transaction({ account: { id: 1, name: 'Inter', type: 'checking', color: null, icon: null } })),
    ).toBe('Sem categoria · Inter')
  })

  it('parcela 3 de 10', () => {
    expect(
      transactionSubtitle(
        transaction({
          category: { id: 3, parent_id: null, name: 'Eletrônicos', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
          account: { id: 2, name: 'Nubank', type: 'credit_card', color: null, icon: null },
          installment: { plan_id: 1, number: 3, total: 10 },
        }),
      ),
    ).toBe('Eletrônicos · Nubank · 3/10')
  })

  it('sem conta carregada: só a primeira parte', () => {
    expect(transactionSubtitle(transaction({}))).toBe('Sem categoria')
  })

  it('pagamento de fatura reconhecido: "Pagamento", mesmo quando ligado a uma transferência', () => {
    expect(
      transactionSubtitle(
        transaction({
          is_card_payment: true,
          transfer_id: 'uuid',
          account: { id: 2, name: 'Nubank', type: 'credit_card', color: null, icon: null },
        }),
      ),
    ).toBe('Pagamento · Nubank')
  })

  it('recorrente', () => {
    expect(
      transactionSubtitle(
        transaction({
          account: { id: 1, name: 'Inter', type: 'checking', color: null, icon: null },
          recurrence: { id: 1, description: 'Aluguel' },
        }),
      ),
    ).toBe('Sem categoria · Inter · Recorrente')
  })
})
