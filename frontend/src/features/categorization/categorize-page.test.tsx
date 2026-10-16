import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

function mockCount(totalPending: number, groupCount: number) {
  usePendingCategorizationCount.mockReturnValue({
    data: { total_pending: totalPending, group_count: groupCount },
    isFetchedAfterMount: true,
  })
}

function page() {
  return (
    <MemoryRouter initialEntries={['/categorizar']}>
      <CategorizePage />
    </MemoryRouter>
  )
}

function renderPage() {
  return render(page())
}

beforeEach(() => {
  vi.clearAllMocks()
  useCategories.mockReset()
  useCategories.mockReturnValue({ data: [] })
  mutateAsync.mockReset()
  useApplyCategorization.mockReturnValue({ mutateAsync, isPending: false })
})

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

  it('seleção vem só do que está carregado: marcar um grupo de entradas e filtrar "Saídas" esvazia a barra', () => {
    const entries = group({
      key: 'salario',
      name: 'Salário ACME',
      out_count: 0,
      in_count: 1,
      net: 500000,
      transactions: [tx({ id: 10, direction: 'in', description: 'Salário ACME', amount: 500000 })],
    })
    const outs = group()
    mockCount(2, 2)
    usePendingCategorization.mockImplementation((filters: { direction?: string } = {}) => {
      const groups = filters.direction === 'out' ? [outs] : [entries, outs]
      return {
        data: { total_pending: 2, group_count: groups.length, groups },
        isPending: false,
        isError: false,
        isPlaceholderData: false,
        refetch: vi.fn(),
      }
    })

    renderPage()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar grupo Salário ACME' }))
    expect(screen.getByText('1 selecionado')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('radio', { name: 'Saídas' }))

    expect(screen.queryByText(/selecionados?$/)).not.toBeInTheDocument()
  })

  it('progresso conta desde a abertura: de 10 para 7 pendentes mostra "3 de 10"', () => {
    mockCount(10, 3)
    mockPending([group()])

    const view = renderPage()
    expect(screen.getByText('0 de 10 (0%)')).toBeInTheDocument()

    mockCount(7, 2)
    view.rerender(page())
    expect(screen.getByText('3 de 10 (30%)')).toBeInTheDocument()
  })

  it('progresso fica oculto até a contagem ser buscada de novo nesta abertura', () => {
    usePendingCategorizationCount.mockReturnValue({ data: { total_pending: 10, group_count: 3 }, isFetchedAfterMount: false })
    mockPending([group()])

    renderPage()

    expect(screen.queryByText(/ de 10/)).not.toBeInTheDocument()
  })

  it('aplicar a sugestão desmarca só os lançamentos daquele grupo e mostra o caminho da categoria', async () => {
    const suggested = group({ transactions: [tx({ id: 1 }), tx({ id: 2 })], out_count: 2, net: -2000, suggestion: { category_id: 50 } })
    const other = group({ key: 'uber', name: 'Uber', transactions: [tx({ id: 3, description: 'Uber trip' })] })
    mockCount(3, 2)
    mockPending([suggested, other])
    useCategories.mockReturnValue({
      data: [category({ id: 40, name: 'Casa' }), category({ id: 50, parent_id: 40, name: 'Compras' })],
    })
    mutateAsync.mockResolvedValue({ categorized: 2, skipped: 0 })

    renderPage()

    expect(screen.getByText('Casa › Compras')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar grupo Shpp Brasil' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar grupo Uber' }))
    expect(screen.getByText('3 selecionados')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar a 2' }))

    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('2 lançamentos → Casa › Compras'))
    expect(screen.getByText('1 selecionado')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Selecionar grupo Uber' })).toHaveAttribute('aria-checked', 'true')
  })

  it('aplicar a sugestão sem nada categorizado mostra uma mensagem neutra', async () => {
    mockCount(1, 1)
    mockPending([group({ suggestion: { category_id: 50 } })])
    useCategories.mockReturnValue({ data: [category({ id: 50 })] })
    mutateAsync.mockResolvedValue({ categorized: 0, skipped: 1 })

    renderPage()
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar a 1' }))

    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('Esses lançamentos já tinham categoria.'))
  })

  it('erro ao aplicar a sugestão avisa e não mexe na seleção', async () => {
    mockCount(2, 1)
    mockPending([group({ transactions: [tx({ id: 1 }), tx({ id: 2 })], out_count: 2, net: -2000, suggestion: { category_id: 50 } })])
    useCategories.mockReturnValue({ data: [category({ id: 50 })] })
    mutateAsync.mockRejectedValue(new Error('boom'))

    renderPage()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar grupo Shpp Brasil' }))
    expect(screen.getByText('2 selecionados')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar a 2' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalled())
    expect(toast.success).not.toHaveBeenCalled()
    expect(screen.getByText('2 selecionados')).toBeInTheDocument()
  })

  it('trava os botões de sugestão enquanto alguma aplicação está em andamento', () => {
    mockCount(1, 1)
    mockPending([group({ suggestion: { category_id: 50 } })])
    useCategories.mockReturnValue({ data: [category({ id: 50 })] })
    useApplyCategorization.mockReturnValue({ mutateAsync, isPending: true })

    renderPage()

    expect(screen.getByRole('button', { name: 'Aplicar a 1' })).toBeDisabled()
  })

  it('"+ N lançamentos" fica no singular com um só escondido', () => {
    const transactions = Array.from({ length: 9 }, (_, i) => tx({ id: i + 1, description: `Lançamento ${i + 1}` }))
    mockCount(9, 1)
    mockPending([group({ transactions, out_count: 9, net: -9000 })])

    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))

    expect(screen.getByRole('button', { name: '+ 1 lançamento' })).toBeInTheDocument()
  })
})
