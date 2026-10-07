import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Transaction } from '@/api/types'
import { ConfirmOccurrenceDialog } from './confirm-occurrence-dialog'

const confirmMutateAsync = vi.fn()

vi.mock('@/api/queries/recurrences', () => ({
  useConfirmOccurrence: () => ({ mutateAsync: confirmMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function transaction(overrides: Partial<Transaction> = {}): Transaction {
  return {
    id: 10,
    account_id: 1,
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
    is_card_payment: false,
    installment: null,
    ...overrides,
  }
}

beforeEach(() => {
  confirmMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

function renderDialog(props: Partial<Parameters<typeof ConfirmOccurrenceDialog>[0]> = {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <ConfirmOccurrenceDialog transaction={transaction()} onOpenChange={vi.fn()} {...props} />
    </QueryClientProvider>,
  )
}

describe('ConfirmOccurrenceDialog', () => {
  it('sem transação, não renderiza nada', () => {
    const { container } = renderDialog({ transaction: null })

    expect(container).toBeEmptyDOMElement()
  })

  it('pré-preenche valor e data previstos', () => {
    renderDialog()

    expect(screen.getByLabelText('Valor')).toHaveValue('1.500,00')
    expect(screen.getByLabelText('Data')).toHaveValue('2026-09-20')
  })

  it('confirma com os valores ajustados', async () => {
    const onOpenChange = vi.fn()
    renderDialog({ onOpenChange })

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.450,00' } })
    fireEvent.change(screen.getByLabelText('Data'), { target: { value: '2026-09-19' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() => expect(confirmMutateAsync).toHaveBeenCalledWith({ id: 10, body: { amount: 145000, date: '2026-09-19' } }))
    expect(toast.success).toHaveBeenCalledWith('Lançamento confirmado.')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('rejeita data no futuro', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Data'), { target: { value: '2099-01-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() => expect(screen.getByText('A data não pode ser no futuro.')).toBeInTheDocument())
    expect(confirmMutateAsync).not.toHaveBeenCalled()
  })

  it('422 do servidor em date aparece no campo', async () => {
    confirmMutateAsync.mockRejectedValueOnce(
      new ApiError(422, 'Dados inválidos.', null, { date: ['A ocorrência já foi confirmada nesta data.'] }),
    )
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() =>
      expect(screen.getByText('A ocorrência já foi confirmada nesta data.')).toBeInTheDocument(),
    )
  })
})
