import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { Transaction } from '@/api/types'
import { TooltipProvider } from '@/components/ui/tooltip'
import { SpendingCategoryRow } from './spending-category-row'
import { rootViewEntries } from './spending-shares'

const useTransactionsPreview = vi.fn()

vi.mock('@/api/queries/transactions', () => ({
  useTransactionsPreview: (...args: unknown[]) => useTransactionsPreview(...args),
}))

const entry = rootViewEntries([{ category_id: 1, name: 'Alimentação', color: null, icon: null, amount: 30000, count: 3, children: [] }], 30000)[0]

const filters = { reportable: true as const, currency: 'BRL', direction: 'out' as const, from: '2026-10-01', to: '2026-10-31', category_id: 1 }

function transaction(overrides: Partial<Transaction>): Transaction {
  return {
    id: 1,
    account_id: 1,
    date: '2026-10-05',
    amount: 5000,
    direction: 'out',
    currency: 'BRL',
    description: 'Mercado X',
    original_description: 'Mercado X',
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

function renderRow() {
  return render(
    <MemoryRouter>
      <TooltipProvider>
        <SpendingCategoryRow entry={entry} currency="BRL" filters={filters} from="2026-10-01" to="2026-10-31" />
      </TooltipProvider>
    </MemoryRouter>,
  )
}

const parentEntry = rootViewEntries(
  [
    {
      category_id: 2,
      name: 'Casa',
      color: null,
      icon: null,
      amount: 50000,
      count: 5,
      children: [
        { category_id: 21, name: 'Aluguel', color: null, icon: null, amount: 40000, count: 1 },
        { category_id: 22, name: 'Luz', color: null, icon: null, amount: 10000, count: 4 },
      ],
    },
  ],
  50000,
)[0]

const parentFilters = { reportable: true as const, currency: 'BRL', direction: 'out' as const, from: '2026-10-01', to: '2026-10-31', category_id: 2 }

function renderParentRow() {
  return render(
    <MemoryRouter>
      <TooltipProvider>
        <SpendingCategoryRow entry={parentEntry} currency="BRL" filters={parentFilters} from="2026-10-01" to="2026-10-31" />
      </TooltipProvider>
    </MemoryRouter>,
  )
}

describe('SpendingCategoryRow', () => {
  it('começa fechada e sem buscar a prévia (useTransactionsPreview chamado com enabled=false)', () => {
    useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })

    renderRow()

    const button = screen.getByRole('button', { name: /Alimentação/ })
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(useTransactionsPreview).toHaveBeenCalledWith(filters, false)
  })

  it('expandir chama a prévia com enabled=true e mostra os lançamentos', () => {
    useTransactionsPreview.mockReturnValue({
      isPending: false,
      isError: false,
      data: { data: [transaction({ description: 'Mercado X' })] },
      refetch: vi.fn(),
    })

    renderRow()

    fireEvent.click(screen.getByRole('button', { name: /Alimentação/ }))

    expect(screen.getByRole('button', { name: /Alimentação/ })).toHaveAttribute('aria-expanded', 'true')
    expect(useTransactionsPreview).toHaveBeenLastCalledWith(filters, true)
    expect(screen.getByText('Mercado X')).toBeInTheDocument()
  })

  it('mostra o esqueleto enquanto a prévia carrega', () => {
    useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })

    renderRow()
    fireEvent.click(screen.getByRole('button', { name: /Alimentação/ }))

    expect(screen.queryByText('Nenhum lançamento encontrado.')).not.toBeInTheDocument()
  })

  it('vazio: "Nenhum lançamento encontrado."', () => {
    useTransactionsPreview.mockReturnValue({ isPending: false, isError: false, data: { data: [] }, refetch: vi.fn() })

    renderRow()
    fireEvent.click(screen.getByRole('button', { name: /Alimentação/ }))

    expect(screen.getByText('Nenhum lançamento encontrado.')).toBeInTheDocument()
  })

  it('erro: mostra "Tentar de novo" que chama refetch', () => {
    const refetch = vi.fn()
    useTransactionsPreview.mockReturnValue({ isPending: false, isError: true, data: undefined, refetch })

    renderRow()
    fireEvent.click(screen.getByRole('button', { name: /Alimentação/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('"Ver todos" aponta para /transacoes com os filtros equivalentes', () => {
    useTransactionsPreview.mockReturnValue({ isPending: false, isError: false, data: { data: [] }, refetch: vi.fn() })

    renderRow()
    fireEvent.click(screen.getByRole('button', { name: /Alimentação/ }))

    const link = screen.getByRole('link', { name: 'Ver todos' })
    expect(link).toHaveAttribute('href', '/transacoes?categoria=1&de=2026-10-01&ate=2026-10-31&tipo=out&moeda=BRL&relatorio=1')
  })

  it('nome da categoria quebra em até duas linhas em vez de truncar', () => {
    useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })

    renderRow()

    const name = screen.getByText('Alimentação')
    expect(name).not.toHaveClass('truncate')
    expect(name).toHaveClass('line-clamp-2')
  })

  it('rótulo de contagem mostra o número isolado para telas estreitas e o texto completo a partir de sm', () => {
    useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })

    renderRow()

    const button = screen.getByRole('button', { name: /Alimentação/ })
    expect(button).toHaveTextContent('3')
    expect(button).toHaveTextContent('3 lançamentos')
  })

  it('colapsar esconde o conteúdo de novo', () => {
    useTransactionsPreview.mockReturnValue({ isPending: false, isError: false, data: { data: [] }, refetch: vi.fn() })

    renderRow()
    const button = screen.getByRole('button', { name: /Alimentação/ })
    fireEvent.click(button)
    expect(screen.getByText('Nenhum lançamento encontrado.')).toBeInTheDocument()

    fireEvent.click(button)
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByText('Nenhum lançamento encontrado.')).not.toBeInTheDocument()
  })

  it('raiz com subcategoria expande para a lista de subcategorias, sem buscar a prévia da própria categoria', () => {
    useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })

    renderParentRow()
    fireEvent.click(screen.getByRole('button', { name: /Casa/ }))

    expect(screen.getByRole('button', { name: /Aluguel/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Luz/ })).toBeInTheDocument()
    expect(useTransactionsPreview).toHaveBeenCalledWith(parentFilters, false)
    expect(useTransactionsPreview).not.toHaveBeenCalledWith(parentFilters, true)
  })

  it('clicar numa subcategoria dentro expande pra prévia de lançamentos dela', () => {
    useTransactionsPreview.mockImplementation((queryFilters: { category_id?: number }, enabled: boolean) => {
      if (enabled && queryFilters.category_id === 21) {
        return { isPending: false, isError: false, data: { data: [transaction({ description: 'Aluguel out' })] }, refetch: vi.fn() }
      }
      return { isPending: true, isError: false, data: undefined, refetch: vi.fn() }
    })

    renderParentRow()
    fireEvent.click(screen.getByRole('button', { name: /Casa/ }))
    fireEvent.click(screen.getByRole('button', { name: /Aluguel/ }))

    expect(screen.getByText('Aluguel out')).toBeInTheDocument()
  })

  it('raiz sem filhos continua expandindo direto pra prévia de lançamentos', () => {
    useTransactionsPreview.mockReturnValue({
      isPending: false,
      isError: false,
      data: { data: [transaction({ description: 'Mercado X' })] },
      refetch: vi.fn(),
    })

    renderRow()
    fireEvent.click(screen.getByRole('button', { name: /Alimentação/ }))

    expect(useTransactionsPreview).toHaveBeenCalledWith(filters, true)
    expect(screen.getByText('Mercado X')).toBeInTheDocument()
  })
})
