import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { queryKeys } from '@/api/query-keys'
import type { Account, Category, Transaction, Transfer } from '@/api/types'
import { getLastUsedAccountId, rememberLastUsedAccountId } from '@/lib/last-used-account'
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
const createTransactionMutateAsync = vi.fn()
const updateTransactionMutateAsync = vi.fn()
const deleteTransactionMutateAsync = vi.fn()

vi.mock('@/api/queries/transactions', () => ({
  useTransaction: () => ({ data: mockTransaction, isPending: false, isError: mockTransactionError }),
  useCreateTransaction: () => ({ mutateAsync: createTransactionMutateAsync }),
  useUpdateTransaction: () => ({ mutateAsync: updateTransactionMutateAsync }),
  useDeleteTransaction: () => ({ mutateAsync: deleteTransactionMutateAsync }),
}))

const createRecurrenceMutateAsync = vi.fn()
const confirmOccurrenceMutateAsync = vi.fn()
const skipOccurrenceMutateAsync = vi.fn()

vi.mock('@/api/queries/recurrences', () => ({
  useCreateRecurrence: () => ({ mutateAsync: createRecurrenceMutateAsync }),
  useConfirmOccurrence: () => ({ mutateAsync: confirmOccurrenceMutateAsync }),
  useSkipOccurrence: () => ({ mutateAsync: skipOccurrenceMutateAsync }),
}))

let mockTransfer: Transfer | undefined
const unlinkTransferMutateAsync = vi.fn()
const createTransferMutateAsync = vi.fn()

vi.mock('@/api/queries/transfers', () => ({
  useTransfer: () => ({ data: mockTransfer, isPending: false, isError: false }),
  useCreateTransfer: () => ({ mutateAsync: createTransferMutateAsync }),
  useUpdateTransfer: () => ({ mutateAsync: vi.fn() }),
}))

vi.mock('@/api/queries/transfer-suggestions', () => ({
  useUnlinkTransfer: () => ({ mutateAsync: unlinkTransferMutateAsync }),
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
    is_card_payment: false,
    installment: null,
    ...overrides,
  }
}

function transfer(overrides: Partial<Transfer> = {}): Transfer {
  return {
    transfer_id: 'uuid-1',
    date: '2026-10-01',
    amount: 1000,
    description: 'Transferência',
    notes: null,
    from: transaction({ id: 1, direction: 'out', transfer_id: 'uuid-1' }),
    to: transaction({ id: 2, direction: 'in', transfer_id: 'uuid-1', account_id: 2 }),
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
  window.localStorage.clear()
  mockAccounts = []
  mockTransaction = undefined
  mockTransactionError = true
  mockTransfer = undefined
  createTransactionMutateAsync.mockReset().mockResolvedValue(transaction({ id: 9 }))
  createRecurrenceMutateAsync.mockReset().mockResolvedValue({ id: 1 })
  confirmOccurrenceMutateAsync.mockReset().mockResolvedValue(undefined)
  skipOccurrenceMutateAsync.mockReset().mockResolvedValue(undefined)
  updateTransactionMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteTransactionMutateAsync.mockReset().mockResolvedValue(undefined)
  unlinkTransferMutateAsync.mockReset().mockResolvedValue(undefined)
  createTransferMutateAsync.mockReset().mockResolvedValue(transfer())
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

  it('criação: com uma conta já usada antes, o padrão é ela, mesmo com outra conta alfabeticamente anterior', () => {
    rememberLastUsedAccountId(2)
    mockAccounts = [account({ id: 1, name: 'Acai' }), account({ id: 2, name: 'Inter' })]

    renderPage(['/transacoes/nova'])

    expect(screen.getByLabelText('Conta')).toHaveTextContent('Inter')
  })

  it('criação: salvar uma despesa grava a conta usada como padrão para a próxima vez', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' })]

    renderPage(['/transacoes/nova'])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '10,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Compra' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar despesa' }))

    await waitFor(() => expect(createTransactionMutateAsync).toHaveBeenCalled())
    expect(getLastUsedAccountId()).toBe(1)
  })

  it('criação: salvar uma transferência grava a conta de origem como padrão para a próxima vez', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' }), account({ id: 2, name: 'Nubank' })]

    renderPage(['/transacoes/nova?tipo=transferencia'])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '10,00' } })
    const toTrigger = screen.getByLabelText('Para')
    fireEvent.pointerDown(toTrigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(toTrigger)
    fireEvent.click(await screen.findByRole('option', { name: 'Nubank' }))
    fireEvent.click(screen.getByRole('button', { name: 'Salvar transferência' }))

    await waitFor(() => expect(createTransferMutateAsync).toHaveBeenCalled())
    expect(getLastUsedAccountId()).toBe(1)
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

  it('transferência com a outra perna comum: excluir avisa que as duas pernas somem', () => {
    mockTransaction = transaction({ id: 1, transfer_id: 'uuid-1' })
    mockTransactionError = false
    mockTransfer = transfer()

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Excluir' }))

    expect(screen.getByText('As duas pernas serão excluídas.')).toBeInTheDocument()
  })

  it('transferência cuja deletes_only_this_leg vem true do backend: excluir avisa que só esta perna some', () => {
    mockTransaction = transaction({ id: 1, transfer_id: 'uuid-1' })
    mockTransactionError = false
    // O backend (TransferResource) já calcula isto por perna a partir da regra de
    // DeleteTransaction::handle(); o frontend só lê o valor, nunca reimplementa a regra.
    mockTransfer = transfer({ from: transaction({ id: 1, direction: 'out', transfer_id: 'uuid-1', deletes_only_this_leg: true }) })

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Excluir' }))

    expect(screen.getByText('Só este lançamento será excluído; o da outra conta volta a ser um lançamento comum.')).toBeInTheDocument()
  })

  it('"Desfazer transferência" chama useUnlinkTransfer e volta para a origem', async () => {
    mockTransaction = transaction({ id: 1, transfer_id: 'uuid-1' })
    mockTransactionError = false
    mockTransfer = transfer()

    renderPage([{ pathname: '/transacoes/1', state: { from: '/cartoes/5' } }])

    fireEvent.click(screen.getByRole('button', { name: 'Desfazer transferência' }))
    fireEvent.click(screen.getByRole('button', { name: 'Desfazer' }))

    await waitFor(() => expect(unlinkTransferMutateAsync).toHaveBeenCalledWith('uuid-1'))
    await waitFor(() => expect(screen.getByText('Cartão')).toBeInTheDocument())
    expect(toast.success).toHaveBeenCalledWith('Transferência desfeita.')
  })

  it('criação com "Repetir" ligado: cria a recorrência a partir da transação e avisa', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' })]

    renderPage(['/transacoes/nova'])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '50,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Streaming' } })
    fireEvent.click(screen.getByLabelText('Repetir'))
    fireEvent.click(screen.getByRole('button', { name: 'Salvar despesa' }))

    await waitFor(() =>
      expect(createRecurrenceMutateAsync).toHaveBeenCalledWith({ transaction_id: 9, frequency: 'monthly' }),
    )
    expect(toast.success).toHaveBeenCalledWith('Recorrência criada.')
    expect(toast.success).toHaveBeenCalledWith('Lançamento salvo.')
  })

  it('criação: quando a transação casa com uma prevista, avisa a confirmação em vez do toast padrão', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' })]
    createTransactionMutateAsync.mockResolvedValue(transaction({ id: 9, recurrence: { id: 2, description: 'Aluguel' } }))

    renderPage(['/transacoes/nova'])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar despesa' }))

    await waitFor(() => expect(createTransactionMutateAsync).toHaveBeenCalled())
    expect(toast.success).toHaveBeenCalledWith('Lançamento previsto de Aluguel confirmado.')
    expect(toast.success).not.toHaveBeenCalledWith('Lançamento salvo.')
  })

  it('"Repetir" ligado, mas a transação já casou com uma prevista: não cria outra recorrência', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' })]
    createTransactionMutateAsync.mockResolvedValue(transaction({ id: 9, recurrence: { id: 2, description: 'Aluguel' } }))

    renderPage(['/transacoes/nova'])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.click(screen.getByLabelText('Repetir'))
    fireEvent.click(screen.getByRole('button', { name: 'Salvar despesa' }))

    await waitFor(() => expect(createTransactionMutateAsync).toHaveBeenCalled())
    expect(createRecurrenceMutateAsync).not.toHaveBeenCalled()
    expect(toast.success).toHaveBeenCalledWith('Lançamento previsto de Aluguel confirmado.')
  })

  it('falha ao criar a recorrência: ainda assim salva e navega, sem recriar a transação', async () => {
    mockAccounts = [account({ id: 1, name: 'Inter' })]
    createRecurrenceMutateAsync.mockRejectedValue(new Error('falhou'))

    renderPage(['/transacoes/nova'])

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '50,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Streaming' } })
    fireEvent.click(screen.getByLabelText('Repetir'))
    fireEvent.click(screen.getByRole('button', { name: 'Salvar despesa' }))

    await waitFor(() => expect(screen.getByText('Lista')).toBeInTheDocument())
    expect(createTransactionMutateAsync).toHaveBeenCalledTimes(1)
    expect(toast.error).toHaveBeenCalledWith('Lançamento salvo, mas não foi possível criar a recorrência.')
    expect(toast.success).toHaveBeenCalledWith('Lançamento salvo.')
  })

  it('ocorrência prevista: mostra o aviso e as ações de confirmar e pular', () => {
    mockTransaction = transaction({ status: 'projected', recurrence: { id: 2, description: 'Aluguel' } })
    mockTransactionError = false

    renderPage()

    expect(screen.getByText(/ocorrência prevista da recorrência "Aluguel"/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Pular esta ocorrência' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Excluir' })).not.toBeInTheDocument()
  })

  it('ocorrência prevista: confirma primeiro (valor/data) e só depois grava os outros campos editados', async () => {
    mockTransaction = transaction({ id: 1, status: 'projected', recurrence: { id: 2, description: 'Aluguel' } })
    mockTransactionError = false

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() => expect(updateTransactionMutateAsync).toHaveBeenCalled())
    expect(confirmOccurrenceMutateAsync).toHaveBeenCalledWith({ id: 1, body: { amount: 1000, date: '2026-10-01' } })
    // A chamada que gravaria categoria/conta/tags/etc. só pode acontecer depois da confirmação
    // ter sido aceita: ela é a que protege contra sobrescrever uma ocorrência já confirmada por
    // outro caminho (ex.: casada durante uma sincronização bancária).
    expect(confirmOccurrenceMutateAsync.mock.invocationCallOrder[0]).toBeLessThan(
      updateTransactionMutateAsync.mock.invocationCallOrder[0],
    )
    expect(toast.success).toHaveBeenCalledWith('Ocorrência confirmada.')
    await waitFor(() => expect(screen.getByText('Lista')).toBeInTheDocument())
  })

  it('ocorrência prevista: confirmar falha, não grava os outros campos nem navega', async () => {
    mockTransaction = transaction({ id: 1, status: 'projected', recurrence: { id: 2, description: 'Aluguel' } })
    mockTransactionError = false
    confirmOccurrenceMutateAsync.mockRejectedValueOnce(new ApiError(409, 'Conflito ao confirmar.', 'some_other_code'))

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() => expect(confirmOccurrenceMutateAsync).toHaveBeenCalled())
    expect(updateTransactionMutateAsync).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('Conflito ao confirmar.')
    expect(toast.success).not.toHaveBeenCalledWith('Ocorrência confirmada.')
    expect(screen.queryByText('Lista')).not.toBeInTheDocument()
  })

  it('ocorrência prevista: confirmar uma já confirmada em outro lugar mostra mensagem específica', async () => {
    mockTransaction = transaction({ id: 1, status: 'projected', recurrence: { id: 2, description: 'Aluguel' } })
    mockTransactionError = false
    confirmOccurrenceMutateAsync.mockRejectedValueOnce(
      new ApiError(409, 'Este lançamento não é uma ocorrência prevista de recorrência.', 'occurrence_not_projected'),
    )

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() => expect(confirmOccurrenceMutateAsync).toHaveBeenCalled())
    expect(updateTransactionMutateAsync).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('Esta previsão já foi confirmada por outro processo. Atualize a página.')
    expect(screen.queryByText('Lista')).not.toBeInTheDocument()
  })

  it('ocorrência prevista: pular explica o que faz e chama o endpoint de pular', async () => {
    mockTransaction = transaction({ id: 1, status: 'projected', recurrence: { id: 2, description: 'Aluguel' } })
    mockTransactionError = false

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Pular esta ocorrência' }))

    expect(screen.getByText('Pular esta ocorrência?')).toBeInTheDocument()
    expect(screen.getByText(/Esta previsão não será lançada/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Pular' }))

    await waitFor(() => expect(skipOccurrenceMutateAsync).toHaveBeenCalledWith(1))
    expect(updateTransactionMutateAsync).not.toHaveBeenCalled()
    expect(toast.success).toHaveBeenCalledWith('Ocorrência pulada.')
    await waitFor(() => expect(screen.getByText('Lista')).toBeInTheDocument())
  })

  it('ocorrência prevista: pular uma já confirmada em outro lugar mostra mensagem específica', async () => {
    mockTransaction = transaction({ id: 1, status: 'projected', recurrence: { id: 2, description: 'Aluguel' } })
    mockTransactionError = false
    skipOccurrenceMutateAsync.mockRejectedValueOnce(
      new ApiError(409, 'Este lançamento não é uma ocorrência prevista de recorrência.', 'occurrence_not_projected'),
    )

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Pular esta ocorrência' }))
    fireEvent.click(screen.getByRole('button', { name: 'Pular' }))

    await waitFor(() => expect(skipOccurrenceMutateAsync).toHaveBeenCalled())
    expect(toast.error).toHaveBeenCalledWith('Esta previsão já foi confirmada por outro processo. Atualize a página.')
    expect(toast.success).not.toHaveBeenCalledWith('Ocorrência pulada.')
  })

  it('status "projected" sem recorrência (ex.: previsão de parcela) não mostra o aviso nem as ações de ocorrência', () => {
    mockTransaction = transaction({ status: 'projected' })
    mockTransactionError = false

    renderPage()

    expect(screen.queryByText(/ocorrência prevista da recorrência/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pular esta ocorrência' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Salvar' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Excluir' })).toBeInTheDocument()
  })
})
