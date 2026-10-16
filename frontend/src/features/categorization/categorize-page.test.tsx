import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import type { Category, PendingCategorizationGroup } from '@/api/types'
import { CategorizePage } from './categorize-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const usePendingCategorizationCount = vi.fn()
const usePendingCategorization = vi.fn()
const mutateAsync = vi.fn()
const useApplyCategorization = vi.fn(() => ({ mutateAsync, isPending: false }))
const useCategories = vi.fn(() => ({ data: [] as Category[] }))

vi.mock('@/api/queries/categorization', () => ({
  usePendingCategorizationCount: (...args: unknown[]) => usePendingCategorizationCount(...args),
  usePendingCategorization: (...args: unknown[]) => usePendingCategorization(...args),
  useApplyCategorization: () => useApplyCategorization(),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => useCategories(),
}))

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
  useNotifications: () => ({ data: undefined, fetchNextPage: vi.fn(), hasNextPage: false, isFetchingNextPage: false, isPending: true }),
  useMarkRead: () => ({ mutate: vi.fn() }),
  useMarkAllRead: () => ({ mutate: vi.fn(), isPending: false }),
}))

function category(overrides: Partial<Category> = {}): Category {
  return {
    id: 50,
    parent_id: null,
    name: 'Compras',
    kind: 'expense',
    icon: 'shopping-bag',
    color: '#14b8a6',
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
    ...overrides,
  }
}

function tx(overrides: Partial<PendingCategorizationGroup['transactions'][number]>): PendingCategorizationGroup['transactions'][number] {
  return {
    id: 1,
    date: '2026-10-10',
    description: 'Pix enviado - Shpp Brasil',
    amount: 1000,
    direction: 'out',
    account_id: 1,
    account_name: 'Banco Inter',
    ...overrides,
  }
}

function group(overrides: Partial<PendingCategorizationGroup> = {}): PendingCategorizationGroup {
  return {
    key: 'shpp',
    name: 'Shpp Brasil',
    out_count: 1,
    in_count: 0,
    net: -1000,
    transactions: [tx({ id: 1 })],
    ...overrides,
  }
}

function mockPending(groups: PendingCategorizationGroup[], overrides: Partial<ReturnType<typeof usePendingCategorization>> = {}) {
  usePendingCategorization.mockReturnValue({
    data: { total_pending: groups.reduce((sum, g) => sum + g.transactions.length, 0), group_count: groups.length, groups },
    isPending: false,
    isError: false,
    refetch: vi.fn(),
    ...overrides,
  })
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/categorizar']}>
      <CategorizePage />
    </MemoryRouter>,
  )
}

describe('CategorizePage', () => {
  it('abre e fecha um grupo', () => {
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 1, group_count: 1 } })
    mockPending([group()])

    renderPage()

    const header = screen.getByRole('button', { name: /Shpp Brasil/ })
    expect(header).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByText('Pix enviado - Shpp Brasil')).not.toBeInTheDocument()

    fireEvent.click(header)
    expect(header).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('Pix enviado - Shpp Brasil')).toBeInTheDocument()

    fireEvent.click(header)
    expect(header).toHaveAttribute('aria-expanded', 'false')
  })

  it('marcar o grupo marca todos os lançamentos, inclusive os fora dos 8 visíveis', () => {
    const transactions = Array.from({ length: 10 }, (_, i) => tx({ id: i + 1, description: `Lançamento ${i + 1}` }))
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 10, group_count: 1 } })
    mockPending([group({ transactions, out_count: 10, net: -10000 })])

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar grupo Shpp Brasil' }))

    expect(screen.getByText('10 selecionados')).toBeInTheDocument()
    fireEvent.click(screen.getByText('+ 2 lançamentos'))
    expect(screen.getByRole('checkbox', { name: 'Selecionar Lançamento 9' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Selecionar Lançamento 10' })).toBeChecked()
  })

  it('estado parcial do checkbox do grupo quando só parte está marcada', () => {
    const transactions = [tx({ id: 1 }), tx({ id: 2, description: 'Pix enviado - Shpp Brasil 2' })]
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 2, group_count: 1 } })
    mockPending([group({ transactions, out_count: 2, net: -2000 })])

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar Pix enviado - Shpp Brasil' }))

    expect(screen.getByRole('checkbox', { name: 'Selecionar grupo Shpp Brasil' })).toHaveAttribute('aria-checked', 'mixed')
  })

  it('"só saídas" marca apenas os lançamentos de saída do grupo', () => {
    const transactions = [
      tx({ id: 1, direction: 'out', description: 'Saída 1' }),
      tx({ id: 2, direction: 'in', description: 'Entrada 1' }),
    ]
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 2, group_count: 1 } })
    mockPending([group({ transactions, out_count: 1, in_count: 1, net: 0 })])

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))
    fireEvent.click(screen.getByText('só saídas (1)'))

    expect(screen.getByText('1 selecionado')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Selecionar Saída 1' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Selecionar Entrada 1' })).not.toBeChecked()
  })

  it('"Aplicar a N" da sugestão chama o apply com os ids do grupo e sem regra', async () => {
    const transactions = [tx({ id: 1 }), tx({ id: 2 })]
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 2, group_count: 1 } })
    mockPending([group({ transactions, out_count: 2, net: -2000, suggestion: { category_id: 50 } })])
    useCategories.mockReturnValue({ data: [category({ id: 50, name: 'Compras' })] })
    mutateAsync.mockResolvedValue({ categorized: 2, skipped: 0 })

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar a 2' }))

    await vi.waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ transaction_ids: [1, 2], category_id: 50 }))
    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('2 lançamentos → Compras'))
  })

  it('estado vazio sem nenhum filtro: "Tudo categorizado"', () => {
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 0, group_count: 0 } })
    mockPending([])

    renderPage()

    expect(screen.getByText('Tudo categorizado')).toBeInTheDocument()
  })

  it('estado de erro mostra "Tentar de novo"', () => {
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 1, group_count: 1 } })
    const refetch = vi.fn()
    usePendingCategorization.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch })

    renderPage()

    expect(screen.getByText('Não foi possível carregar os lançamentos pendentes.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })

  it('mostra o subtítulo com a contagem geral', () => {
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 5, group_count: 2 } })
    mockPending([group()])

    renderPage()

    expect(screen.getByText('5 lançamentos sem categoria · 2 grupos')).toBeInTheDocument()
  })
})
