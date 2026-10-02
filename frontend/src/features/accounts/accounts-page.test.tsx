import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { AccountsPage } from './accounts-page'

const refetch = vi.fn()

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: undefined, isPending: false, isError: true, refetch }),
  useCreateAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

function renderPage() {
  const client = new QueryClient()

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/contas']}>
        <AccountsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AccountsPage', () => {
  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    renderPage()

    expect(screen.getByText('Não foi possível carregar as contas.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })
})
