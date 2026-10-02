import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Account, Transaction } from '@/api/types'
import { TransactionFormPage } from './transaction-form-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

let mockAccounts: Account[] = []

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: mockAccounts, isPending: false }),
}))

vi.mock('@/api/queries/cards', () => ({
  useStatementPreview: () => ({ data: undefined }),
  useCardStatements: () => ({ data: undefined }),
}))

let mockTransaction: Transaction | undefined
let mockTransactionError = true
const updateTransactionMutateAsync = vi.fn()
const deleteTransactionMutateAsync = vi.fn()

vi.mock('@/api/queries/transactions', () => ({
  useTransaction: () => ({ data: mockTransaction, isPending: false, isError: mockTransactionError }),
  useCreateTransaction: () => ({ mutateAsync: vi.fn() }),
  useUpdateTransaction: () => ({ mutateAsync: updateTransactionMutateAsync }),
  useDeleteTransaction: () => ({ mutateAsync: deleteTransactionMutateAsync }),
}))

vi.mock('@/api/queries/transfers', () => ({
  useTransfer: () => ({ data: undefined, isPending: false, isError: false }),
  useCreateTransfer: () => ({ mutateAsync: vi.fn() }),
  useUpdateTransfer: () => ({ mutateAsync: vi.fn() }),
}))

function account(overrides: Partial<Account> = {}): Account {
  return {
    id: 1,
    name: 'Inter',
    type: 'checking',
    currency: 'BRL',
    opening_balance: 0,
    balance: 0,
    credit_limit: null,
    closing_day: null,
    due_day: null,
    last_four: null,
    color: null,
    icon: null,
    is_archived: false,
    ...overrides,
  }
}

function transaction(overrides: Partial<Transaction> = {}): Transaction {
  return {
    id: 1,
    account_id: 1,
    date: '2026-10-01',
    amount: 1000,
    direction: 'out',
    currency: 'BRL',
    description: 'Lançamento',
    original_description: 'Lançamento',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    category: null,
    tags: [],
    status: 'posted',
    source: 'manual',
    categorized_by: null,
    is_ignored: false,
    transfer_id: null,
    statement_id: null,
    installment: null,
    ...overrides,
  }
}

function renderPage(initialEntries: NonNullable<Parameters<typeof MemoryRouter>[0]['initialEntries']> = ['/transacoes/1']) {
  const client = new QueryClient()

  return render(
    <StrictMode>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={initialEntries}>
          <Routes>
            <Route path="/transacoes/nova" element={<TransactionFormPage />} />
            <Route path="/transacoes/:id" element={<TransactionFormPage />} />
            <Route path="/transacoes" element={<div>Lista</div>} />
            <Route path="/cartoes/5" element={<div>Cartão</div>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </StrictMode>,
  )
}

beforeEach(() => {
  mockAccounts = []
  mockTransaction = undefined
  mockTransactionError = true
  updateTransactionMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteTransactionMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('TransactionFormPage', () => {
  it('ao falhar o carregamento, redireciona e mostra o toast de erro uma única vez (mesmo sob StrictMode)', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByText('Lista')).toBeInTheDocument())

    expect(toast.error).toHaveBeenCalledTimes(1)
    expect(toast.error).toHaveBeenCalledWith('Lançamento não encontrado.')
  })

  it('parcela: abre com "Editar parcela", Valor desabilitado e o aviso', () => {
    mockTransaction = transaction({ installment: { plan_id: 1, number: 3, total: 10 }, statement_id: 50 })
    mockTransactionError = false

    renderPage()

    expect(screen.getByText('Editar parcela')).toBeInTheDocument()
    expect(screen.getByLabelText('Valor')).toBeDisabled()
    expect(screen.getByText(/Parcela 3 de 10\. Valor, data e conta seguem o parcelamento/)).toBeInTheDocument()
  })

  it('criação: "?conta=" pré-seleciona a conta', () => {
    mockAccounts = [account({ id: 1, name: 'Inter' }), account({ id: 2, name: 'Nubank', type: 'credit_card' })]

    renderPage(['/transacoes/nova?conta=2'])

    expect(screen.getByLabelText('Conta')).toHaveTextContent('Nubank')
  })

  it('salvar volta para o location.state.from', async () => {
    mockTransaction = transaction()
    mockTransactionError = false

    renderPage([{ pathname: '/transacoes/1', state: { from: '/cartoes/5' } }])

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(screen.getByText('Cartão')).toBeInTheDocument())
    expect(updateTransactionMutateAsync).toHaveBeenCalled()
  })

  it('criação a partir do cartão ("Registrar compra"): salvar volta para a origem', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' })]

    renderPage([{ pathname: '/transacoes/nova', state: { from: '/cartoes/5' } }])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '10,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Compra' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar despesa' }))

    await waitFor(() => expect(screen.getByText('Cartão')).toBeInTheDocument())
  })
})
