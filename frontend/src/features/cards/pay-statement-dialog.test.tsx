import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Account, CardStatement } from '@/api/types'
import { today } from '@/lib/date'
import { PayStatementDialog } from './pay-statement-dialog'

const mutateAsync = vi.fn()

vi.mock('@/api/queries/cards', () => ({
  usePayStatement: () => ({ mutateAsync, isPending: false }),
}))

function account(overrides: Partial<Account> = {}): Account {
  return {
    id: 1,
    name: 'Nubank',
    type: 'credit_card',
    currency: 'BRL',
    opening_balance: 0,
    balance: -50000,
    credit_limit: 500000,
    closing_day: 10,
    due_day: 17,
    last_four: '1234',
    color: null,
    icon: null,
    is_archived: false,
    ...overrides,
  }
}

const cardAccount = account()
const walletAccount = account({ id: 2, name: 'Carteira', type: 'cash', credit_limit: null, closing_day: null, due_day: null, last_four: null })

let mockAccounts: Account[] = [cardAccount, walletAccount]

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: mockAccounts }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function statement(overrides: Partial<CardStatement> = {}): CardStatement {
  return {
    id: 10,
    account_id: 1,
    closing_date: '2026-10-10',
    due_date: '2026-10-17',
    reported_total: null,
    total: 120000,
    paid: 0,
    remaining: 120000,
    status: 'open',
    days_until_due: 14,
    is_overdue: false,
    has_divergence: false,
    ...overrides,
  }
}

function renderDialog(target: CardStatement = statement(), onOpenChange: (open: boolean) => void = () => {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <PayStatementDialog open statement={target} currency="BRL" onOpenChange={onOpenChange} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockAccounts = [cardAccount, walletAccount]
  mutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('PayStatementDialog', () => {
  it('abre com o valor igual ao restante da fatura e a data de hoje', () => {
    renderDialog()

    expect(screen.getByLabelText('Valor')).toHaveValue('1.200,00')
    expect(screen.getByLabelText('Data')).toHaveValue(today())
  })

  it('restante zero: o campo valor abre vazio', () => {
    renderDialog(statement({ remaining: 0 }))

    expect(screen.getByLabelText('Valor')).toHaveValue('')
  })

  it('a lista de contas de origem não mostra o cartão', () => {
    renderDialog()

    fireEvent.click(screen.getByRole('combobox'))
    const options = screen.getAllByRole('option').map((option) => option.textContent)

    expect(options).toEqual(['Carteira'])
  })

  it('salvar chama usePayStatement().mutateAsync e mostra toast de sucesso', async () => {
    const onOpenChange = vi.fn()
    renderDialog(statement(), onOpenChange)

    fireEvent.click(screen.getByRole('button', { name: 'Pagar' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({
        id: 10,
        body: { from_account_id: 2, amount: 120000, date: today() },
      }),
    )
    expect(toast.success).toHaveBeenCalledWith('Pagamento registrado.')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('409 statement_already_paid mostra a mensagem do backend em toast e mantém o diálogo aberto', async () => {
    mutateAsync.mockRejectedValue(new ApiError(409, 'Esta fatura já foi paga.', 'statement_already_paid'))
    const onOpenChange = vi.fn()
    renderDialog(statement(), onOpenChange)

    fireEvent.click(screen.getByRole('button', { name: 'Pagar' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Esta fatura já foi paga.'))
    expect(onOpenChange).not.toHaveBeenCalledWith(false)
    expect(screen.getByRole('button', { name: 'Pagar' })).toBeInTheDocument()
  })

  it('sem conta ativa para pagar (só o cartão): avisa e desabilita "Pagar"', () => {
    mockAccounts = [cardAccount]
    renderDialog()

    expect(screen.getByText('Cadastre uma conta (corrente, poupança ou dinheiro) para pagar a fatura.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Pagar' })).toBeDisabled()
  })
})
