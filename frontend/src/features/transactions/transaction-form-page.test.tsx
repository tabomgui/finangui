import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Account, Category, Transaction } from '@/api/types'
import { TransactionFormPage } from './transaction-form-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

let mockAccounts: Account[] = []

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: (includeArchived: boolean) => ({
    data: includeArchived ? mockAccounts : mockAccounts.filter((account) => !account.is_archived),
    isPending: false,
  }),
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
    connection_id: null,
    provider_balance: null,
    provider_synced_at: null,
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
    categorization: null,
    is_ignored: false,
    transfer_id: null,
    statement_id: null,
    installment: null,
    ...overrides,
  }
}

function category(overrides: Partial<Category> = {}): Category {
  return {
    id: 1,
    parent_id: null,
    name: 'Mercado',
    kind: 'expense',
    icon: null,
    color: null,
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
    ...overrides,
  }
}

function renderPage(
  initialEntries: NonNullable<Parameters<typeof MemoryRouter>[0]['initialEntries']> = ['/transacoes/1'],
  options: { categories?: Category[] } = {},
) {
  const client = new QueryClient()
  if (options.categories) client.setQueryData(queryKeys.categories(true), options.categories)

  return render(
    <StrictMode>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={initialEntries}>
          <Routes>
            <Route path="/transacoes/nova" element={<TransactionFormPage />} />
            <Route path="/transacoes/:id" element={<TransactionFormPage />} />
            <Route path="/transacoes" element={<div>Lista</div>} />
            <Route path="/cartoes/5" element={<div>Cartão</div>} />
            <Route path="/regras/nova" element={<div>Nova regra</div>} />
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

  it('criação: "?conta=" aceita uma conta arquivada', () => {
    mockAccounts = [
      account({ id: 1, name: 'Inter' }),
      account({ id: 2, name: 'Conta antiga', is_archived: true }),
    ]

    renderPage(['/transacoes/nova?conta=2'])

    expect(screen.getByLabelText('Conta')).toHaveTextContent('Conta antiga')
  })

  it('criação: sem "?conta=" válido, usa a primeira conta ativa, não uma arquivada', () => {
    mockAccounts = [
      account({ id: 1, name: 'Conta antiga', is_archived: true }),
      account({ id: 2, name: 'Inter' }),
    ]

    renderPage(['/transacoes/nova'])

    expect(screen.getByLabelText('Conta')).toHaveTextContent('Inter')
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

  it('botão "Criar regra a partir deste lançamento" leva para /regras/nova com o id', () => {
    mockTransaction = transaction()
    mockTransactionError = false

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Criar regra a partir deste lançamento' }))

    expect(screen.getByText('Nova regra')).toBeInTheDocument()
  })

  it('salvar com troca de categoria mostra o toast com ação de criar regra', async () => {
    mockTransaction = transaction({
      category_id: 1,
      category: { id: 1, parent_id: null, name: 'Mercado', icon: null, color: null, is_transfer: false, is_transfer_effective: false },
    })
    mockTransactionError = false

    renderPage(['/transacoes/1'], {
      categories: [category({ id: 1, name: 'Mercado' }), category({ id: 2, name: 'Transporte' })],
    })

    fireEvent.click(screen.getByLabelText('Categoria'))
    fireEvent.click(screen.getByText('Transporte'))
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(updateTransactionMutateAsync).toHaveBeenCalled())
    expect(toast.success).toHaveBeenCalledWith(
      'Lançamento atualizado.',
      expect.objectContaining({
        description: 'Aplicar esta categoria a lançamentos parecidos?',
        action: expect.objectContaining({ label: 'Criar regra' }),
      }),
    )
  })

  it('salvar sem troca de categoria mostra o toast simples', async () => {
    mockTransaction = transaction({ category_id: null })
    mockTransactionError = false

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(updateTransactionMutateAsync).toHaveBeenCalled())
    expect(toast.success).toHaveBeenCalledWith('Lançamento atualizado.')
  })
})
