import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import type { Transaction, TransferSuggestion } from '@/api/types'
import { TransferSuggestionsPage } from './transfer-suggestions-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const refetch = vi.fn()
const fetchNextPage = vi.fn()
const detectMutateAsync = vi.fn()
const useTransferSuggestions = vi.fn()

vi.mock('@/api/queries/transfer-suggestions', () => ({
  useTransferSuggestions: (...args: unknown[]) => useTransferSuggestions(...args),
  useDetectTransfers: () => ({ mutateAsync: detectMutateAsync, isPending: false }),
  useAcceptSuggestion: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDismissSuggestion: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

function leg(overrides: Partial<Transaction>): Transaction {
  return {
    id: 1,
    account_id: 1,
    account: { id: 1, name: 'Inter', type: 'checking', color: null, icon: null },
    date: '2026-10-01',
    amount: 5000,
    direction: 'out',
    currency: 'BRL',
    description: 'Transferência',
    original_description: 'Transferência',
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
    installment: null,
    ...overrides,
  }
}

function suggestion(id: number): TransferSuggestion {
  return {
    id,
    score: 0.6,
    out: leg({ id: id * 10, direction: 'out' }),
    in: leg({ id: id * 10 + 1, direction: 'in', account: { id: 2, name: 'Nubank', type: 'checking', color: null, icon: null } }),
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/transferencias/sugestoes']}>
      <Routes>
        <Route path="/transferencias/sugestoes" element={<TransferSuggestionsPage />} />
        <Route path="/transacoes" element={<div>Transações</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('TransferSuggestionsPage', () => {
  it('lista vazia mostra "Nenhuma sugestão pendente."', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage,
      refetch,
    })

    renderPage()

    expect(screen.getByText('Nenhuma sugestão pendente.')).toBeInTheDocument()
  })

  it('mostra as sugestões da primeira página', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [suggestion(1), suggestion(2)] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage,
      refetch,
    })

    renderPage()

    expect(screen.getAllByRole('button', { name: 'Juntar' })).toHaveLength(2)
  })

  it('erro mostra opção de tentar de novo', () => {
    useTransferSuggestions.mockReturnValue({
      data: undefined,
      isPending: false,
      isError: true,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage,
      refetch,
    })

    renderPage()

    expect(screen.getByText('Não foi possível carregar as sugestões.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })

  it('"Procurar agora" chama a detecção e mostra o resultado no toast', async () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [] }] },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      isFetchingNextPage: false,
      hasNextPage: false,
      fetchNextPage,
      refetch,
    })
    detectMutateAsync.mockResolvedValue({ linked: 2, suggested: 1 })

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Procurar agora' }))

    await vi.waitFor(() => expect(detectMutateAsync).toHaveBeenCalled())
    expect(toast.success).toHaveBeenCalledWith('2 ligadas, 1 sugestão')
  })
})
