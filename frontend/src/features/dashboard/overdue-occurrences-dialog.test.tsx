import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Transaction } from '@/api/types'
import { OverdueOccurrencesDialog } from './overdue-occurrences-dialog'

const skipMutateAsync = vi.fn()
let overdueData: Transaction[] | undefined
let skipState: { isPending: boolean; variables?: number } = { isPending: false }

vi.mock('@/api/queries/recurrences', () => ({
  useOverdueOccurrences: () => ({ data: overdueData, isPending: overdueData === undefined }),
  useSkipOccurrence: () => ({ mutateAsync: skipMutateAsync, ...skipState }),
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
    is_card_payment: false,
    installment: null,
    ...overrides,
  }
}

beforeEach(() => {
  overdueData = undefined
  skipState = { isPending: false }
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

  it('os botões incluem a descrição no aria-label, para distinguir linhas iguais na leitura de tela', () => {
    overdueData = [transaction({ id: 10, description: 'Aluguel' }), transaction({ id: 11, description: 'Internet' })]

    renderDialog()

    expect(screen.getByRole('button', { name: 'Aconteceu: Aluguel' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Não aconteceu: Aluguel' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Aconteceu: Internet' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Não aconteceu: Internet' })).toBeInTheDocument()
  })

  it('pular uma linha desabilita só os botões dela, não os das outras', () => {
    overdueData = [transaction({ id: 10, description: 'Aluguel' }), transaction({ id: 11, description: 'Internet' })]
    skipState = { isPending: true, variables: 10 }

    renderDialog()

    expect(screen.getByRole('button', { name: 'Não aconteceu: Aluguel' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Aconteceu: Aluguel' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Não aconteceu: Internet' })).not.toBeDisabled()
    expect(screen.getByRole('button', { name: 'Aconteceu: Internet' })).not.toBeDisabled()
  })
})
