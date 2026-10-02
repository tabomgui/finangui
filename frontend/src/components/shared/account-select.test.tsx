import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Account } from '@/api/types'
import { AccountSelect } from './account-select'

const account = (overrides: Partial<Account>): Account => ({
  id: 1,
  name: 'Inter',
  type: 'checking',
  currency: 'BRL',
  opening_balance: 0,
  balance: 0,
  color: null,
  icon: null,
  is_archived: false,
  ...overrides,
})

describe('AccountSelect', () => {
  it('mostra a conta selecionada mesmo arquivada', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.accounts(true), [account({ id: 1 }), account({ id: 2, name: 'Antiga', is_archived: true })])

    render(
      <QueryClientProvider client={client}>
        <AccountSelect id="acc" value={2} onChange={() => {}} />
      </QueryClientProvider>,
    )

    expect(screen.getByRole('combobox')).toHaveTextContent('Antiga')
  })

  it('com includeArchived, lista arquivadas após as ativas com sufixo', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.accounts(true), [
      account({ id: 1, name: 'Antiga', is_archived: true }),
      account({ id: 2, name: 'Inter' }),
    ])

    render(
      <QueryClientProvider client={client}>
        <AccountSelect id="acc" value={null} onChange={() => {}} includeArchived />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('combobox'))
    const options = screen.getAllByRole('option').map((option) => option.textContent)

    expect(options).toEqual(['Inter', 'Antiga (arquivada)'])
  })

  it('sem includeArchived, não lista contas arquivadas (exceto a já selecionada)', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.accounts(true), [
      account({ id: 1, name: 'Antiga', is_archived: true }),
      account({ id: 2, name: 'Inter' }),
    ])

    render(
      <QueryClientProvider client={client}>
        <AccountSelect id="acc" value={null} onChange={() => {}} />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('combobox'))
    const options = screen.getAllByRole('option').map((option) => option.textContent)

    expect(options).toEqual(['Inter'])
  })
})
