import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CategoryComparison, MonthlyEvolution } from '@/api/types'
import { ReportsPage } from './reports-page'

const evolutionRefetch = vi.fn()
const comparisonRefetch = vi.fn()

let evolutionState: { data: MonthlyEvolution | undefined; isPending: boolean; isError: boolean; isPlaceholderData: boolean }
let comparisonState: { data: CategoryComparison | undefined; isPending: boolean; isError: boolean; isPlaceholderData: boolean }

vi.mock('@/api/queries/reports', () => ({
  useMonthlyReport: () => ({ ...evolutionState, refetch: evolutionRefetch }),
  useCategoryComparison: () => ({ ...comparisonState, refetch: comparisonRefetch }),
}))
vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
  useNotifications: () => ({ data: undefined, fetchNextPage: vi.fn(), hasNextPage: false, isFetchingNextPage: false, isPending: true }),
  useMarkRead: () => ({ mutate: vi.fn() }),
  useMarkAllRead: () => ({ mutate: vi.fn(), isPending: false }),
}))

function monthlyEvolution(overrides: Partial<MonthlyEvolution> = {}): MonthlyEvolution {
  return {
    currency: 'BRL',
    months: [
      { month: '2026-09', income: 500000, expense: 300000, net: 200000 },
      { month: '2026-10', income: 150000, expense: 80000, net: 70000 },
    ],
    ...overrides,
  }
}

function categoryComparison(overrides: Partial<CategoryComparison> = {}): CategoryComparison {
  return {
    currency: 'BRL',
    items: [{ category_id: 1, name: 'Moradia', icon: null, color: null, a: 100000, b: 150000, delta: 50000, delta_percent: 50 }],
    totals: { a: 100000, b: 150000, delta: 50000, delta_percent: 50 },
    ...overrides,
  }
}

function renderPage(url = '/relatorios') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <ReportsPage />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  evolutionRefetch.mockReset()
  comparisonRefetch.mockReset()
  evolutionState = { data: undefined, isPending: true, isError: false, isPlaceholderData: false }
  comparisonState = { data: undefined, isPending: true, isError: false, isPlaceholderData: false }
})

describe('ReportsPage', () => {
  it('mostra esqueletos de carregamento', () => {
    renderPage()

    expect(screen.queryByText('Moradia')).not.toBeInTheDocument()
  })

  it('mostra erro com "Tentar de novo" para a evolução mensal', () => {
    evolutionState = { data: undefined, isPending: false, isError: true, isPlaceholderData: false }
    comparisonState = { data: categoryComparison(), isPending: false, isError: false, isPlaceholderData: false }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(evolutionRefetch).toHaveBeenCalled()
  })

  it('mostra erro com "Tentar de novo" para a comparação por categoria', () => {
    evolutionState = { data: monthlyEvolution(), isPending: false, isError: false, isPlaceholderData: false }
    comparisonState = { data: undefined, isPending: false, isError: true, isPlaceholderData: false }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(comparisonRefetch).toHaveBeenCalled()
  })

  it('mostra o estado vazio quando não há movimentação no período', () => {
    evolutionState = { data: monthlyEvolution({ months: [{ month: '2026-10', income: 0, expense: 0, net: 0 }] }), isPending: false, isError: false, isPlaceholderData: false }
    comparisonState = { data: categoryComparison({ items: [], totals: { a: 0, b: 0, delta: 0 } }), isPending: false, isError: false, isPlaceholderData: false }
    renderPage()

    expect(screen.getByText('Nenhuma movimentação no período')).toBeInTheDocument()
    expect(screen.getByText('Nenhuma despesa nos períodos selecionados')).toBeInTheDocument()
  })

  it('mostra o gráfico de evolução e a tabela de comparação por categoria', () => {
    evolutionState = { data: monthlyEvolution(), isPending: false, isError: false, isPlaceholderData: false }
    comparisonState = { data: categoryComparison(), isPending: false, isError: false, isPlaceholderData: false }
    renderPage('/relatorios?mesA=2026-09&mesB=2026-10')

    expect(screen.getByRole('img', { name: /receita e despesa por mês/i })).toBeInTheDocument()
    expect(screen.getByText('Moradia')).toBeInTheDocument()
    expect(screen.getByText('Período A')).toBeInTheDocument()
    expect(screen.getByText('Período B')).toBeInTheDocument()
  })
})
