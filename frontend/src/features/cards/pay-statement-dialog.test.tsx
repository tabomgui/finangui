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

const accounts: Account[] = [
  {
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
  },
  {
    id: 2,
    name: 'Carteira',
    type: 'cash',
    currency: 'BRL',
    opening_balance: 200000,
    balance: 200000,
    credit_limit: null,
    closing_day: null,
    due_day: null,
    last_four: null,
    color: null,
    icon: null,
    is_archived: false,
  },
]

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: accounts }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const statement: CardStatement = {
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
  has_divergence: false,
}

function renderDialog(onOpenChange: (open: boolean) => void = () => {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <PayStatementDialog open statement={statement} currency="BRL" onOpenChange={onOpenChange} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
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

  it('a lista de contas de origem não mostra o cartão', () => {
    renderDialog()

    fireEvent.click(screen.getByRole('combobox'))
    const options = screen.getAllByRole('option').map((option) => option.textContent)

    expect(options).toEqual(['Carteira'])
  })

  it('salvar chama usePayStatement().mutateAsync e mostra toast de sucesso', async () => {
    const onOpenChange = vi.fn()
    renderDialog(onOpenChange)

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

  it('409 statement_already_paid mostra a mensagem do backend em toast', async () => {
    mutateAsync.mockRejectedValue(new ApiError(409, 'Esta fatura já foi paga.', 'statement_already_paid'))
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Pagar' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Esta fatura já foi paga.'))
  })
})
