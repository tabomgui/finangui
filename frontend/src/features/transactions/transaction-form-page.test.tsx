import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { TransactionFormPage } from './transaction-form-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [], isPending: false }),
}))

vi.mock('@/api/queries/transactions', () => ({
  useTransaction: () => ({ data: undefined, isPending: false, isError: true }),
  useCreateTransaction: () => ({ mutateAsync: vi.fn() }),
  useUpdateTransaction: () => ({ mutateAsync: vi.fn() }),
  useDeleteTransaction: () => ({ mutateAsync: vi.fn() }),
}))

vi.mock('@/api/queries/transfers', () => ({
  useTransfer: () => ({ data: undefined, isPending: false, isError: false }),
  useCreateTransfer: () => ({ mutateAsync: vi.fn() }),
  useUpdateTransfer: () => ({ mutateAsync: vi.fn() }),
}))

function renderPage() {
  const client = new QueryClient()

  return render(
    <StrictMode>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/transacoes/1']}>
          <Routes>
            <Route path="/transacoes/:id" element={<TransactionFormPage />} />
            <Route path="/transacoes" element={<div>Lista</div>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </StrictMode>,
  )
}

describe('TransactionFormPage', () => {
  it('ao falhar o carregamento, redireciona e mostra o toast de erro uma única vez (mesmo sob StrictMode)', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByText('Lista')).toBeInTheDocument())

    expect(toast.error).toHaveBeenCalledTimes(1)
    expect(toast.error).toHaveBeenCalledWith('Lançamento não encontrado.')
  })
})
