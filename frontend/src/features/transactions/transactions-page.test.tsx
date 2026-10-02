import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import { TransactionsPage } from './transactions-page'

const refetch = vi.fn()

vi.mock('@/api/queries/transactions', () => ({
  useTransactions: () => ({
    data: undefined,
    isPending: false,
    isError: true,
    isPlaceholderData: false,
    isFetchingNextPage: false,
    hasNextPage: false,
    fetchNextPage: vi.fn(),
    refetch,
  }),
}))

function renderPage() {
  const client = new QueryClient()
  client.setQueryData(queryKeys.tags(), [])

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/transacoes']}>
        <TransactionsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('TransactionsPage', () => {
  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    renderPage()

    expect(screen.getByText('Não foi possível carregar as transações.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })
})
