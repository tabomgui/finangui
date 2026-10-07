import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { Transaction } from '@/api/types'
import { TooltipProvider } from '@/components/ui/tooltip'
import { TransactionRow } from './transaction-row'

vi.mock('@/api/queries/rules', () => ({
  useRules: () => ({ data: [] }),
}))

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
  categorization: null,
  is_ignored: false,
  transfer_id: null,
  statement_id: null,
  is_card_payment: false,
  installment: null,
  ...overrides,
})

function renderRow(transaction: Transaction) {
  return render(
    <MemoryRouter>
      <TooltipProvider>
        <TransactionRow transaction={transaction} />
      </TooltipProvider>
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

  it('mostra o número da parcela no subtítulo', () => {
    renderRow(
      transaction({
        category: { id: 3, parent_id: null, name: 'Eletrônicos', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
        installment: { plan_id: 1, number: 3, total: 10 },
      }),
    )

    expect(screen.getByText(/3\/10/)).toBeInTheDocument()
  })

  it('mostra o ícone de origem da categoria quando não é manual', () => {
    renderRow(
      transaction({
        category: { id: 1, parent_id: null, name: 'Mercado', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
        categorization: { source: 'history' },
      }),
    )

    expect(screen.getByLabelText('Categorizada pelo histórico')).toBeInTheDocument()
  })

  it('não mostra ícone de origem para categorização manual', () => {
    renderRow(
      transaction({
        category: { id: 1, parent_id: null, name: 'Mercado', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
        categorization: { source: 'manual' },
      }),
    )

    expect(screen.queryByLabelText(/Categorizada|Categoria informada/)).not.toBeInTheDocument()
  })
})
