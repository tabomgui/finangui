import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Transaction } from '@/api/types'
import { OverdueOccurrencesDialog } from './overdue-occurrences-dialog'

const skipMutateAsync = vi.fn()
let overdueData: Transaction[] | undefined

vi.mock('@/api/queries/recurrences', () => ({
  useOverdueOccurrences: () => ({ data: overdueData, isPending: overdueData === undefined }),
  useSkipOccurrence: () => ({ mutateAsync: skipMutateAsync, isPending: false }),
  useConfirmOccurrence: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function transaction(overrides: Partial<Transaction> = {}): Transaction {
  return {
    id: 10,
    account_id: 1,
    account: { id: 1, name: 'Nubank', type: 'checking', color: null, icon: null },
    date: '2026-09-20',
    amount: 150000,
    direction: 'out',
    currency: 'BRL',
    description: 'Aluguel',
    original_description: 'Aluguel',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    tags: [],
    status: 'projected',
    source: 'recurrence',
    categorized_by: null,
    categorization: null,
    is_ignored: false,
    transfer_id: null,
    statement_id: null,
    installment: null,
    ...overrides,
  }
}

beforeEach(() => {
  overdueData = undefined
  skipMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

function renderDialog(props: Partial<Parameters<typeof OverdueOccurrencesDialog>[0]> = {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <OverdueOccurrencesDialog open onOpenChange={vi.fn()} {...props} />
    </QueryClientProvider>,
  )
}

describe('OverdueOccurrencesDialog', () => {
  it('lista cada ocorrência atrasada com descrição, conta, data e valor', () => {
    overdueData = [transaction()]

    renderDialog()

    expect(screen.getByText('Aluguel')).toBeInTheDocument()
    expect(screen.getByText(/Nubank/)).toBeInTheDocument()
    expect(screen.getByText(/20\/09\/2026/)).toBeInTheDocument()
    expect(screen.getByText('-R$ 1.500,00')).toBeInTheDocument()
  })

  it('sem pendências, mostra estado vazio', () => {
    overdueData = []

    renderDialog()

    expect(screen.getByText('Nenhum lançamento previsto pendente.')).toBeInTheDocument()
  })

  it('"Não aconteceu" pula a ocorrência', async () => {
    overdueData = [transaction()]

    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: /Não aconteceu/ }))

    await waitFor(() => expect(skipMutateAsync).toHaveBeenCalledWith(10))
    expect(toast.success).toHaveBeenCalled()
  })

  it('"Aconteceu" abre o mini formulário de confirmação', () => {
    overdueData = [transaction()]

    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: /Aconteceu/ }))

    expect(screen.getByText('Confirmar Aluguel')).toBeInTheDocument()
  })
})
