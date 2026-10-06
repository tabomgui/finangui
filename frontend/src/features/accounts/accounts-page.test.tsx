import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Account, BankConnection } from '@/api/types'
import { AccountsPage } from './accounts-page'

const refetch = vi.fn()

let accountsState: { data: Account[] | undefined; isPending: boolean; isError: boolean }
let meState: { data: { banking_enabled: boolean; primary_currency: string } | undefined }
let connectionsState: { data: BankConnection[] | undefined; isPending?: boolean; isError?: boolean }
const refetchConnections = vi.fn()

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ ...accountsState, refetch }),
  useCreateAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/auth', () => ({
  useMe: () => meState,
}))

const connectTokenMutateAsync = vi.fn()

vi.mock('@/api/queries/bank-connections', () => ({
  useBankConnections: () => ({ ...connectionsState, refetch: refetchConnections }),
  useSyncConnection: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDisconnect: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useConnectToken: () => ({ mutateAsync: connectTokenMutateAsync, isPending: false }),
  useCreateConnection: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useMarkReconnected: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useLinkAccounts: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

type MockPluggyProps = { onSuccess: (data: { item: { id: string } }) => void; onClose: () => void; onError: () => void }
const pluggyInstances: MockPluggyProps[] = []

vi.mock('pluggy-connect-sdk', () => ({
  PluggyConnect: class {
    props: MockPluggyProps
    constructor(props: MockPluggyProps) {
      this.props = props
      pluggyInstances.push(props)
    }
    init() {
      return Promise.resolve()
    }
    destroy() {
      return Promise.resolve()
    }
  },
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
    connection_id: null,
    provider_balance: null,
    provider_synced_at: null,
    ...overrides,
  }
}

function connection(overrides: Partial<BankConnection> = {}): BankConnection {
  return {
    id: 1,
    provider: 'pluggy',
    status: 'active',
    institution_name: 'Nubank',
    institution_logo_url: null,
    last_synced_at: null,
    last_error: null,
    accounts: [],
    pending_accounts: [],
    unlinked_accounts: [],
    ...overrides,
  }
}

describe('AccountsPage', () => {
  beforeEach(() => {
    meState = { data: undefined }
    connectionsState = { data: undefined }
    connectTokenMutateAsync.mockReset().mockResolvedValue({ token: 'tok-1', itemId: 'item-9' })
    refetchConnections.mockReset()
    pluggyInstances.length = 0
  })

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

  it('não mostra "Importar extrato" no menu de uma conta arquivada', async () => {
    accountsState = { data: [account({ id: 6, name: 'Conta antiga', is_archived: true })], isPending: false, isError: false }
    renderPage()

    const trigger = screen.getByRole('button', { name: 'Ações da conta Conta antiga' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)

    expect(await screen.findByRole('menuitem', { name: 'Desarquivar' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Importar extrato' })).not.toBeInTheDocument()
  })

  it('agrupa contas conectadas por conexão e mostra as contas manuais separadas', () => {
    accountsState = { data: [account({ id: 1, name: 'Conta manual' })], isPending: false, isError: false }
    connectionsState = { data: [connection({ id: 10, institution_name: 'Itaú' })] }
    renderPage()

    expect(screen.getByText('Itaú')).toBeInTheDocument()
    expect(screen.getByText('Contas manuais')).toBeInTheDocument()
    expect(screen.getByText('Conta manual')).toBeInTheDocument()
  })

  it('não mostra o rótulo "Contas manuais" sem nenhuma conexão', () => {
    accountsState = { data: [account({ id: 1, name: 'Conta manual' })], isPending: false, isError: false }
    connectionsState = { data: [] }
    renderPage()

    expect(screen.queryByText('Contas manuais')).not.toBeInTheDocument()
  })

  it('mostra o botão "Conectar banco" só quando banking_enabled', () => {
    accountsState = { data: [], isPending: false, isError: false }
    connectionsState = { data: [] }
    meState = { data: { banking_enabled: true, primary_currency: 'BRL' } }
    renderPage()

    expect(screen.getByRole('button', { name: 'Conectar banco' })).toBeInTheDocument()
  })

  it('"Conectar banco" leva para o card da Pluggy em Configurações quando banking está desligado', () => {
    accountsState = { data: [], isPending: false, isError: false }
    connectionsState = { data: [] }
    meState = { data: { banking_enabled: false, primary_currency: 'BRL' } }
    renderPage()

    expect(screen.queryByRole('button', { name: 'Conectar banco' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Conectar banco' })).toHaveAttribute('href', '/configuracoes#pluggy')
  })

  it('mostra a faixa de reconexão quando uma conexão precisa de reautenticação', () => {
    accountsState = { data: [], isPending: false, isError: false }
    connectionsState = { data: [connection({ id: 11, institution_name: 'C6', status: 'needs_reauth' })] }
    renderPage()

    expect(screen.getByText('O C6 pediu para reconectar.')).toBeInTheDocument()
  })

  it('reconectar pela faixa busca o token com connection_id e abre o widget', async () => {
    accountsState = { data: [], isPending: false, isError: false }
    connectionsState = { data: [connection({ id: 11, institution_name: 'C6', status: 'needs_reauth' })] }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Reconectar' }))

    await vi.waitFor(() => expect(connectTokenMutateAsync).toHaveBeenCalledWith({ connection_id: 11 }))
    await vi.waitFor(() => expect(pluggyInstances).toHaveLength(1))
  })

  it('mostra skeleton na seção de bancos enquanto as conexões ainda carregam', () => {
    accountsState = { data: [], isPending: false, isError: false }
    connectionsState = { data: undefined, isPending: true }
    const { container } = renderPage()

    expect(container.querySelectorAll('[data-slot="skeleton"]').length).toBeGreaterThan(0)
  })

  it('mostra erro com "Tentar de novo" quando as conexões falham, sem afetar as contas manuais', () => {
    accountsState = { data: [account({ id: 1, name: 'Conta manual' })], isPending: false, isError: false }
    connectionsState = { data: undefined, isError: true }
    renderPage()

    expect(screen.getByText('Não foi possível carregar os bancos conectados.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetchConnections).toHaveBeenCalled()
  })

  it('não decide o estado vazio das contas manuais antes das conexões carregarem (evita o "flash" errado)', () => {
    accountsState = { data: [], isPending: false, isError: false }
    connectionsState = { data: undefined, isPending: true }
    renderPage()

    // Nem "Nenhuma conta ainda" nem "Nenhuma conta manual": as conexões ainda não resolveram, não
    // dá pra saber qual das duas é a correta.
    expect(screen.queryByText('Nenhuma conta ainda')).not.toBeInTheDocument()
    expect(screen.queryByText('Nenhuma conta manual')).not.toBeInTheDocument()
  })
})
