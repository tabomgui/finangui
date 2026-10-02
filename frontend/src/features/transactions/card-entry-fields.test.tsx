import { fireEvent, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Account, CardStatement } from '@/api/types'
import { CardEntryFields } from './card-entry-fields'
import { entryDefaults, type EntryValues } from './form-values'

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
const checkingAccount = account({ id: 2, name: 'Inter', type: 'checking', credit_limit: null, closing_day: null, due_day: null, last_four: null })
const otherCardAccount = account({ id: 3, name: 'Outro Cartão' })

let mockAccounts: Account[] = [cardAccount, checkingAccount, otherCardAccount]
let mockPreview: { due_date: string } | undefined = { due_date: '2026-10-17' }
let mockStatements: CardStatement[] = []

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: mockAccounts }),
}))

vi.mock('@/api/queries/cards', () => ({
  useStatementPreview: () => ({ data: mockPreview }),
  useCardStatements: () => ({ data: mockStatements }),
}))

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

type HarnessProps = {
  defaultValues: EntryValues
  mode: 'create' | 'edit'
  initialAccountId: number | null
  initialDate?: string
  initialStatementId?: number | null
}

function Harness({ defaultValues, ...rest }: HarnessProps) {
  const form = useForm<EntryValues>({ defaultValues })
  return <CardEntryFields form={form} {...rest} />
}

beforeEach(() => {
  mockAccounts = [cardAccount, checkingAccount, otherCardAccount]
  mockPreview = { due_date: '2026-10-17' }
  mockStatements = []
})

describe('CardEntryFields', () => {
  it('criação com conta cartão: mostra Parcelas e o texto da prévia', () => {
    render(
      <Harness
        defaultValues={entryDefaults({ direction: 'out', accountId: 1, today: '2026-10-05' })}
        mode="create"
        initialAccountId={null}
      />,
    )

    expect(screen.getByText('Parcelas')).toBeInTheDocument()
    expect(screen.getByText('Entra na fatura que vence em 17/10/2026.')).toBeInTheDocument()
  })

  it('escolhendo 3x, o texto passa a falar da primeira parcela', async () => {
    render(
      <Harness
        defaultValues={entryDefaults({ direction: 'out', accountId: 1, today: '2026-10-05' })}
        mode="create"
        initialAccountId={null}
      />,
    )

    const trigger = screen.getByLabelText('Parcelas')
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('option', { name: '3x' }))

    expect(
      screen.getByText('Primeira parcela na fatura que vence em 17/10/2026. O valor informado é o total da compra.'),
    ).toBeInTheDocument()
  })

  it('com conta corrente, nada aparece', () => {
    const { container } = render(
      <Harness
        defaultValues={entryDefaults({ direction: 'out', accountId: 2, today: '2026-10-05' })}
        mode="create"
        initialAccountId={null}
      />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('edição na mesma conta: mostra o select de Fatura com as opções da lista', () => {
    mockStatements = [statement({ id: 50 }), statement({ id: 51, due_date: '2026-11-17', status: 'closed' })]

    render(
      <Harness
        defaultValues={{ ...entryDefaults({ direction: 'out', accountId: 1, today: '2026-10-05' }), statement_id: 50 }}
        mode="edit"
        initialAccountId={1}
        initialDate="2026-10-05"
        initialStatementId={50}
      />,
    )

    expect(screen.getByText('Fatura')).toBeInTheDocument()
    expect(screen.getByText('Vence 17/10/2026 · Aberta')).toBeInTheDocument()
  })

  it('override não sobrevive à troca de cartão: o select de fatura não aparece numa conta diferente da original', () => {
    mockPreview = { due_date: '2026-11-17' }

    render(
      <Harness
        defaultValues={{ ...entryDefaults({ direction: 'out', accountId: 3, today: '2026-10-05' }), statement_id: 99 }}
        mode="edit"
        initialAccountId={1}
        initialDate="2026-10-05"
        initialStatementId={50}
      />,
    )

    expect(screen.queryByText('Fatura')).not.toBeInTheDocument()
    expect(screen.getByText('Entra na fatura que vence em 17/11/2026.')).toBeInTheDocument()
  })

  it('Ajuste: mudar a data (mesma conta, sem escolher fatura) esconde o select e mostra a prévia', () => {
    mockPreview = { due_date: '2026-11-17' }

    render(
      <Harness
        defaultValues={{ ...entryDefaults({ direction: 'out', accountId: 1, today: '2026-10-06' }), statement_id: 50 }}
        mode="edit"
        initialAccountId={1}
        initialDate="2026-10-05"
        initialStatementId={50}
      />,
    )

    expect(screen.queryByText('Fatura')).not.toBeInTheDocument()
    expect(screen.getByText('Entra na fatura que vence em 17/11/2026.')).toBeInTheDocument()
  })
})
