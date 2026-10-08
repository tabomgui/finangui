import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import { today } from '@/lib/date'
import { TransactionsPage } from './transactions-page'

const refetch = vi.fn()
const useTransactions = vi.fn()

vi.mock('@/api/queries/transactions', () => ({
  useTransactions: (...args: unknown[]) => useTransactions(...args),
}))

type SuggestionsPageData = { data: { pages: { data: { id: number }[]; meta: { next_cursor: string | null } }[] } }

const useTransferSuggestions = vi.fn<() => SuggestionsPageData>(() => ({
  data: { pages: [{ data: [], meta: { next_cursor: null } }] },
}))

vi.mock('@/api/queries/transfer-suggestions', () => ({
  useTransferSuggestions: () => useTransferSuggestions(),
}))

function renderPage(initialEntry = '/transacoes') {
  const client = new QueryClient()
  client.setQueryData(queryKeys.tags(), [])

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <TransactionsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('TransactionsPage', () => {
  it('mostra o subtítulo do cabeçalho', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage()

    expect(screen.getByText('Receitas, despesas e transferências')).toBeInTheDocument()
  })

  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    useTransactions.mockReturnValue({
      data: undefined,
      isPending: false,
      isError: true,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage()

    expect(screen.getByText('Não foi possível carregar as transações.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('por padrão, limita a lista a hoje (sem lançamentos futuros projetados)', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage()

    expect(useTransactions).toHaveBeenCalledWith({ to: today() })
  })

  it('"Mostrar lançamentos futuros" remove o limite implícito de hoje', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage('/transacoes?futuros=1')

    expect(useTransactions).toHaveBeenCalledWith({})
  })

  it('um filtro "até" explícito continua valendo mesmo sem mostrar futuros', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage('/transacoes?ate=2026-12-31')

    expect(useTransactions).toHaveBeenCalledWith({ to: '2026-12-31' })
  })

  it('o switch de lançamentos futuros reflete e grava o parâmetro na URL', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage()

    const toggle = screen.getByRole('switch', { name: 'Mostrar lançamentos futuros' })
    expect(toggle).not.toBeChecked()

    fireEvent.click(toggle)

    expect(useTransactions).toHaveBeenLastCalledWith({})
  })

  it('com sugestões de transferência pendentes, mostra o link para a página delas', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })
    useTransferSuggestions.mockReturnValueOnce({
      data: { pages: [{ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: 'abc' } }] },
    })

    renderPage()

    const link = screen.getByRole('link', { name: '2+ sugestões de transferência' })
    expect(link).toHaveAttribute('href', '/transferencias/sugestoes')
  })

  it('sem sugestões de transferência pendentes, não mostra o link', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch,
    })

    renderPage()

    expect(screen.queryByText(/sugest(ão|ões) de transferência/)).not.toBeInTheDocument()
  })
})
