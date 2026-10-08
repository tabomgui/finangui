import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { DashboardAccount } from '@/api/types'
import { AccountsCard } from './accounts-card'

function account(id: number): DashboardAccount {
  return { id, name: `Conta ${id}`, type: 'checking', currency: 'BRL', color: null, icon: null, balance: 1000 * id }
}

function renderCard(accounts: DashboardAccount[]) {
  return render(
    <MemoryRouter>
      <AccountsCard accounts={accounts} />
    </MemoryRouter>,
  )
}

describe('AccountsCard', () => {
  it('até 5 contas: mostra todas, sem o link "Ver todas as contas"', () => {
    const accounts = [1, 2, 3, 4, 5].map(account)

    renderCard(accounts)

    for (const a of accounts) expect(screen.getByText(a.name)).toBeInTheDocument()
    expect(screen.queryByText('Ver todas as contas')).not.toBeInTheDocument()
  })

  // Mesmo teto de `useRecentTransactions(5)`: sem ele, uma lista grande de contas desequilibra a
  // altura do par "Contas"/"Últimos lançamentos" no Início (ver dashboard-page.tsx).
  it('mais de 5 contas: mostra só as 5 primeiras e o link "Ver todas as contas" para /contas', () => {
    const accounts = Array.from({ length: 18 }, (_, i) => account(i + 1))

    renderCard(accounts)

    for (const a of accounts.slice(0, 5)) expect(screen.getByText(a.name)).toBeInTheDocument()
    for (const a of accounts.slice(5)) expect(screen.queryByText(a.name)).not.toBeInTheDocument()

    const link = screen.getByRole('link', { name: 'Ver todas as contas' })
    expect(link).toHaveAttribute('href', '/contas')
  })
})
