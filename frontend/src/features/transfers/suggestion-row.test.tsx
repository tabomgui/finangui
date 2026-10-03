import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Transaction, TransferSuggestion } from '@/api/types'
import { SuggestionRow } from './suggestion-row'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const acceptMutateAsync = vi.fn()
const dismissMutateAsync = vi.fn()

vi.mock('@/api/queries/transfer-suggestions', () => ({
  useAcceptSuggestion: () => ({ mutateAsync: acceptMutateAsync, isPending: false }),
  useDismissSuggestion: () => ({ mutateAsync: dismissMutateAsync, isPending: false }),
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
    description: 'Transferência enviada',
    original_description: 'Transferência enviada',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    category: null,
    tags: [],
    status: 'posted',
    source: 'pluggy',
    categorized_by: null,
    categorization: null,
    is_ignored: false,
    transfer_id: null,
    statement_id: null,
    installment: null,
    ...overrides,
  }
}

function suggestion(overrides: Partial<TransferSuggestion> = {}): TransferSuggestion {
  return {
    id: 9,
    score: 0.6,
    out: leg({ id: 1, direction: 'out', account: { id: 1, name: 'Inter', type: 'checking', color: null, icon: null } }),
    in: leg({
      id: 2,
      direction: 'in',
      description: 'Transferência recebida',
      account: { id: 2, name: 'Nubank', type: 'checking', color: null, icon: null },
    }),
    ...overrides,
  }
}

beforeEach(() => {
  acceptMutateAsync.mockReset().mockResolvedValue(undefined)
  dismissMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

describe('SuggestionRow', () => {
  it('mostra as duas pernas com conta, data, descrição e valor', () => {
    render(<SuggestionRow suggestion={suggestion()} />)

    expect(screen.getByText('Transferência enviada')).toBeInTheDocument()
    expect(screen.getByText('Transferência recebida')).toBeInTheDocument()
    expect(screen.getByText('Inter · 01/10/2026')).toBeInTheDocument()
    expect(screen.getByText('Nubank · 01/10/2026')).toBeInTheDocument()
  })

  it('"Juntar" aceita a sugestão e mostra o toast', async () => {
    render(<SuggestionRow suggestion={suggestion({ id: 9 })} />)

    fireEvent.click(screen.getByRole('button', { name: 'Juntar' }))

    await vi.waitFor(() => expect(acceptMutateAsync).toHaveBeenCalledWith(9))
    expect(toast.success).toHaveBeenCalledWith('Transferência ligada.')
  })

  it('"Não é transferência" descarta a sugestão', async () => {
    render(<SuggestionRow suggestion={suggestion({ id: 9 })} />)

    fireEvent.click(screen.getByRole('button', { name: 'Não é transferência' }))

    await vi.waitFor(() => expect(dismissMutateAsync).toHaveBeenCalledWith(9))
  })
})
