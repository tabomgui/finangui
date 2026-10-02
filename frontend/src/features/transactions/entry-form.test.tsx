import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Account, CardStatement, Category, Transaction } from '@/api/types'
import { EntryForm } from './entry-form'
import { entryDefaults, toTransactionBody, type EntryValues } from './form-values'

function category(overrides: Partial<Category>): Category {
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

function statement(overrides: Partial<CardStatement> = {}): CardStatement {
  return {
    id: 50,
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
    ...overrides,
  }
}

const cardA = account({ id: 1, name: 'Nubank' })
const cardB = account({ id: 2, name: 'Outro Cartão' })
const checking = account({ id: 3, name: 'Inter', type: 'checking', credit_limit: null, closing_day: null, due_day: null, last_four: null })

let mockAccounts: Account[] = [cardA, cardB, checking]
let mockStatements: CardStatement[] = []

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: mockAccounts }),
}))

vi.mock('@/api/queries/cards', () => ({
  useStatementPreview: () => ({ data: undefined, isError: false }),
  useCardStatements: () => ({ data: mockStatements }),
}))

const transaction = {
  id: 1,
  account_id: 3,
  date: '2026-10-01',
  amount: 1000,
  direction: 'in',
  currency: 'BRL',
  description: 'Reembolso',
  original_description: 'Reembolso',
  description_locked: false,
  notes: null,
  payee: null,
  category_id: 9,
  tags: [],
  status: 'posted',
  source: 'manual',
  categorized_by: 'manual',
  is_ignored: false,
  transfer_id: null,
  statement_id: null,
  installment: null,
} as Transaction

async function chooseOption(triggerLabel: string, optionName: string | RegExp) {
  const trigger = screen.getByLabelText(triggerLabel)
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: optionName }))
}

describe('EntryForm', () => {
  it('mantém a categoria ao editar uma receita com categoria de despesa', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.categories(true), [category({ id: 9, name: 'Mercado', kind: 'expense' })])
    mockAccounts = []

    render(
      <QueryClientProvider client={client}>
        <EntryForm defaultValues={entryDefaults({ transaction })} onSubmit={vi.fn()} submitLabel="Salvar" />
      </QueryClientProvider>,
    )

    expect(screen.getByText('Mercado')).toBeInTheDocument()
  })

  it('mostra "Valor total" quando installments > 1', () => {
    const client = new QueryClient()
    mockAccounts = []

    render(
      <QueryClientProvider client={client}>
        <EntryForm
          defaultValues={{ ...entryDefaults({ direction: 'out', accountId: null, today: '2026-10-05' }), installments: 3 }}
          onSubmit={vi.fn()}
          submitLabel="Salvar"
        />
      </QueryClientProvider>,
    )

    expect(screen.getByLabelText('Valor total')).toBeInTheDocument()
  })

  it('com lockedReason: trava Valor, Conta e Data e mostra o aviso', () => {
    const client = new QueryClient()
    mockAccounts = []

    render(
      <QueryClientProvider client={client}>
        <EntryForm
          defaultValues={entryDefaults({ direction: 'out', accountId: null, today: '2026-10-05' })}
          onSubmit={vi.fn()}
          submitLabel="Salvar"
          mode="edit"
          lockedReason="Parcela 3 de 10."
        />
      </QueryClientProvider>,
    )

    expect(screen.getByLabelText(/Valor/)).toBeDisabled()
    expect(screen.getByLabelText('Conta')).toBeDisabled()
    expect(screen.getByLabelText('Data')).toBeDisabled()
    expect(screen.getByRole('note')).toHaveTextContent('Parcela 3 de 10.')
  })

  it('trocar de conta zera parcelas (volta o rótulo pra "Valor" e tira installments do corpo)', async () => {
    const client = new QueryClient()
    mockAccounts = [cardA, checking]
    let captured: EntryValues | undefined

    render(
      <QueryClientProvider client={client}>
        <EntryForm
          defaultValues={entryDefaults({ direction: 'out', accountId: 1, today: '2026-10-05' })}
          onSubmit={async (values) => {
            captured = values
          }}
          submitLabel="Salvar"
        />
      </QueryClientProvider>,
    )

    fireEvent.change(screen.getByLabelText(/Valor/), { target: { value: '100,00' } })
    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Compra' } })

    await chooseOption('Parcelas', '3x')
    expect(screen.getByLabelText('Valor total')).toBeInTheDocument()

    await chooseOption('Conta', 'Inter')
    expect(screen.getByLabelText('Valor')).toBeInTheDocument()
    expect(screen.queryByLabelText('Valor total')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(captured).toBeDefined())
    expect(toTransactionBody(captured!)).not.toHaveProperty('installments')
  })

  it('escolher outra fatura e depois trocar de cartão: statement_id volta ao original e não é enviado', async () => {
    const client = new QueryClient()
    mockAccounts = [cardA, cardB]
    mockStatements = [statement({ id: 50 }), statement({ id: 60, due_date: '2026-11-17' })]
    let captured: EntryValues | undefined

    render(
      <QueryClientProvider client={client}>
        <EntryForm
          defaultValues={entryDefaults({ transaction: { ...transaction, account_id: 1, statement_id: 50, direction: 'out' } })}
          onSubmit={async (values) => {
            captured = values
          }}
          submitLabel="Salvar"
          mode="edit"
        />
      </QueryClientProvider>,
    )

    await chooseOption('Fatura', /17\/11\/2026/)
    await chooseOption('Conta', 'Outro Cartão')

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(captured).toBeDefined())
    expect(toTransactionBody(captured!, { initialStatementId: 50 })).not.toHaveProperty('statement_id')
  })
})
