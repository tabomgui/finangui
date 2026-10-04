import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { MonthBudget } from '@/api/types'
import { BudgetsPage } from './budgets-page'

const refetch = vi.fn()

let budgetState: { data: MonthBudget | undefined; isPending: boolean; isError: boolean; isPlaceholderData: boolean }

vi.mock('@/api/queries/budgets', () => ({
  useMonthBudget: () => ({ ...budgetState, refetch }),
  useSaveBudget: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteBudget: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({ data: [] }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function monthBudget(overrides: Partial<MonthBudget> = {}): MonthBudget {
  return {
    month: '2026-10',
    currency: 'BRL',
    items: [
      {
        category: { id: 2, name: 'Moradia', icon: null, color: null },
        amount: 150000,
        source: 'default',
        spent: 50000,
        remaining: 100000,
        percent: 33,
      },
    ],
    totals: { budgeted: 150000, spent: 50000 },
    unbudgeted_spent: 2000,
    ...overrides,
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/orcamento']}>
      <BudgetsPage />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  refetch.mockReset()
  budgetState = { data: undefined, isPending: true, isError: false, isPlaceholderData: false }
})

describe('BudgetsPage', () => {
  it('mostra esqueletos de carregamento', () => {
    renderPage()

    expect(screen.queryByText('Moradia')).not.toBeInTheDocument()
  })

  it('mostra erro com botão "Tentar de novo"', () => {
    budgetState = { data: undefined, isPending: false, isError: true, isPlaceholderData: false }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('mostra o resumo, a lista de categorias e o total fora do orçamento', () => {
    budgetState = { data: monthBudget(), isPending: false, isError: false, isPlaceholderData: false }
    renderPage()

    expect(screen.getByText('Moradia')).toBeInTheDocument()
    expect(screen.getByText('33%')).toBeInTheDocument()
    expect(screen.getByText(/Fora do orçamento/)).toBeInTheDocument()
  })

  it('sem categorias orçadas mostra o estado vazio', () => {
    budgetState = { data: monthBudget({ items: [], totals: { budgeted: 0, spent: 0 } }), isPending: false, isError: false, isPlaceholderData: false }
    renderPage()

    expect(screen.getByText('Nenhuma categoria orçada este mês')).toBeInTheDocument()
  })

  it('abre o diálogo de orçar categoria', () => {
    budgetState = { data: monthBudget(), isPending: false, isError: false, isPlaceholderData: false }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Orçar categoria' }))

    expect(screen.getByText('Defina um limite mensal para a categoria.')).toBeInTheDocument()
  })
})
