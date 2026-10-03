import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { Account } from '@/api/types'
import { AccountsPage } from './accounts-page'

const refetch = vi.fn()

let accountsState: { data: Account[] | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ ...accountsState, refetch }),
  useCreateAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/auth', () => ({
  useMe: () => ({ data: undefined }),
}))

function renderPage(initialEntry = '/contas') {
  const client = new QueryClient()

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          <Route path="/contas" element={<AccountsPage />} />
          <Route path="/importar" element={<div>Importar extrato: tela</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function account(overrides: Partial<Account>): Account {
  return {
    id: 1,
    name: 'Nubank',
    type: 'checking',
    currency: 'BRL',
    opening_balance: 0,
    balance: 0,
    color: null,
    icon: null,
    credit_limit: null,
    closing_day: null,
    due_day: null,
    last_four: null,
    is_archived: false,
    ...overrides,
  }
}

describe('AccountsPage', () => {
  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    accountsState = { data: undefined, isPending: false, isError: true }
    renderPage()

    expect(screen.getByText('Não foi possível carregar as contas.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('menu da conta tem a opção "Importar extrato" que navega com a conta pré-selecionada', async () => {
    accountsState = { data: [account({ id: 5, name: 'Nubank' })], isPending: false, isError: false }
    renderPage()

    const trigger = screen.getByRole('button', { name: 'Ações da conta Nubank' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Importar extrato' }))

    expect(await screen.findByText('Importar extrato: tela')).toBeInTheDocument()
  })
})
