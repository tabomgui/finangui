import { render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { StatementTransactions } from './statement-transactions'

const useTransactions = vi.fn()

vi.mock('@/api/queries/transactions', () => ({
  useTransactions: (...args: unknown[]) => useTransactions(...args),
}))

describe('StatementTransactions', () => {
  it('pede os lançamentos só pelo statement_id, sem limitar a data (a fatura precisa das parcelas projetadas)', () => {
    useTransactions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isError: false,
      isPlaceholderData: false,
      isPending: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage: vi.fn(),
      refetch: vi.fn(),
    })

    render(<StatementTransactions statementId={42} />)

    expect(useTransactions).toHaveBeenCalledWith({ statement_id: 42 })
  })
})
