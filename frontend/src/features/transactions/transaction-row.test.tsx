import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { Transaction } from '@/api/types'
import { TransactionRow } from './transaction-row'

const transaction = (overrides: Partial<Transaction>): Transaction => ({
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
  is_ignored: false,
  transfer_id: null,
  ...overrides,
})

function renderRow(transaction: Transaction) {
  return render(
    <MemoryRouter>
      <TransactionRow transaction={transaction} />
    </MemoryRouter>,
  )
}

describe('TransactionRow', () => {
  it('colore o valor normalmente para uma categoria comum', () => {
    renderRow(
      transaction({
        category: { id: 1, parent_id: null, name: 'Mercado', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
      }),
    )

    expect(screen.getByText('Mercado', { exact: false })).toBeInTheDocument()
    expect(screen.getByText(/R\$/)).toHaveClass('text-expense')
  })

  it('não colore o valor e mantém o nome da categoria quando is_transfer_effective', () => {
    renderRow(
      transaction({
        category: { id: 2, parent_id: null, name: 'Ajuste entre contas', icon: null, color: null, is_transfer: false, is_transfer_effective: true },
      }),
    )

    expect(screen.getByText('Ajuste entre contas', { exact: false })).toBeInTheDocument()
    const amount = screen.getByText(/R\$/)
    expect(amount).not.toHaveClass('text-income')
    expect(amount).not.toHaveClass('text-expense')
  })
})
