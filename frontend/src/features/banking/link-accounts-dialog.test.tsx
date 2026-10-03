import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ComponentProps } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { queryKeys } from '@/api/query-keys'
import type { Account, BankConnection, ProviderAccount } from '@/api/types'
import { LinkAccountsDialog } from './link-accounts-dialog'

const linkMutateAsync = vi.fn()
let accountsData: Account[] = []
let accountsPending = false

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: accountsData, isPending: accountsPending }),
}))

vi.mock('@/api/queries/bank-connections', () => ({
  useLinkAccounts: () => ({ mutateAsync: linkMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

beforeEach(() => {
  accountsData = []
  accountsPending = false
  linkMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
  vi.mocked(toast.error).mockReset()
})

function providerAccount(overrides: Partial<ProviderAccount> = {}): ProviderAccount {
  return {
    external_id: 'ext-1',
    name: 'Conta corrente',
    number: '1234-5',
    kind: 'checking',
    currency: 'BRL',
    balance: 15000,
    suggested_account_id: null,
    ...overrides,
  }
}

function account(overrides: Partial<Account> = {}): Account {
  return {
    id: 1,
    name: 'Minha conta antiga',
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

function connection(pendingAccounts: ProviderAccount[]): BankConnection {
  return {
    id: 10,
    provider: 'pluggy',
    status: 'pending_link',
    institution_name: 'Banco Fictício',
    institution_logo_url: null,
    last_synced_at: null,
    last_error: null,
    accounts: [],
    pending_accounts: pendingAccounts,
    unlinked_accounts: [],
  }
}

function renderDialog(props: Partial<ComponentProps<typeof LinkAccountsDialog>> & { connection: BankConnection }) {
  const client = new QueryClient()
  return { client, ...render(
    <QueryClientProvider client={client}>
      <LinkAccountsDialog open onOpenChange={vi.fn()} {...props} />
    </QueryClientProvider>,
  ) }
}

async function selectOption(selectLabel: string, optionLabel: string) {
  const trigger = screen.getByRole('combobox', { name: selectLabel })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: optionLabel }))
}

describe('LinkAccountsDialog', () => {
  it('mostra nome, número mascarado, tipo e saldo de cada conta do banco', () => {
    renderDialog({ connection: connection([providerAccount({ name: 'Conta corrente', number: '0001-9999-4321', balance: 15000 })]) })

    expect(screen.getByText('Conta corrente')).toBeInTheDocument()
    expect(screen.getByText('•••• 4321 · Conta corrente')).toBeInTheDocument()
    expect(screen.getByText(/150,00/)).toBeInTheDocument()
  })

  it('mostra um skeleton por conta pendente e desabilita Confirmar enquanto as contas manuais carregam', () => {
    accountsPending = true
    renderDialog({ connection: connection([providerAccount(), providerAccount({ external_id: 'ext-2' })]) })

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeDisabled()
  })

  it('pré-seleciona a conta sugerida quando compatível', () => {
    accountsData = [account({ id: 7, name: 'Conta já existente' })]
    renderDialog({ connection: connection([providerAccount({ suggested_account_id: 7 })]) })

    expect(screen.getByRole('combobox', { name: 'Vínculo de Conta corrente' })).toHaveTextContent('Conta já existente')
  })

  it('padrão é "Criar conta nova" sem sugestão', () => {
    renderDialog({ connection: connection([providerAccount()]) })

    expect(screen.getByRole('combobox', { name: 'Vínculo de Conta corrente' })).toHaveTextContent('Criar conta nova')
  })

  it('só lista contas manuais compatíveis (mesmo tipo e moeda, sem conexão)', async () => {
    accountsData = [
      account({ id: 1, name: 'Corrente compatível', type: 'checking', currency: 'BRL' }),
      account({ id: 2, name: 'Poupança', type: 'savings', currency: 'BRL' }),
      account({ id: 3, name: 'Dólar', type: 'checking', currency: 'USD' }),
      account({ id: 4, name: 'Já conectada', type: 'checking', currency: 'BRL', connection_id: 99 }),
    ]
    renderDialog({ connection: connection([providerAccount()]) })

    const trigger = screen.getByRole('combobox', { name: 'Vínculo de Conta corrente' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)

    expect(await screen.findByRole('option', { name: 'Corrente compatível' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Poupança' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Dólar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Já conectada' })).not.toBeInTheDocument()
  })

  it('uma conta manual escolhida numa linha some das opções das outras linhas', async () => {
    accountsData = [account({ id: 1, name: 'Corrente compatível', type: 'checking', currency: 'BRL' })]
    renderDialog({
      connection: connection([
        providerAccount({ external_id: 'ext-1', name: 'Conta A' }),
        providerAccount({ external_id: 'ext-2', name: 'Conta B' }),
      ]),
    })

    await selectOption('Vínculo de Conta A', 'Corrente compatível')

    const triggerB = screen.getByRole('combobox', { name: 'Vínculo de Conta B' })
    fireEvent.pointerDown(triggerB, { button: 0, pointerType: 'mouse' })
    fireEvent.click(triggerB)

    expect(screen.queryByRole('option', { name: 'Corrente compatível' })).not.toBeInTheDocument()
  })

  it('confirmar envia o vínculo correto (conta nova e conta existente) e fecha', async () => {
    accountsData = [account({ id: 1, name: 'Corrente compatível', type: 'checking', currency: 'BRL' })]
    const onOpenChange = vi.fn()
    renderDialog({
      connection: connection([
        providerAccount({ external_id: 'ext-1', name: 'Conta corrente' }),
        providerAccount({ external_id: 'ext-2', name: 'Cartão', kind: 'credit_card' }),
      ]),
      onOpenChange,
    })

    await selectOption('Vínculo de Conta corrente', 'Corrente compatível')
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await vi.waitFor(() =>
      expect(linkMutateAsync).toHaveBeenCalledWith({
        id: 10,
        body: {
          links: [
            { external_id: 'ext-1', account_id: 1 },
            { external_id: 'ext-2', account_id: null },
          ],
        },
      }),
    )
    expect(toast.success).toHaveBeenCalledWith('Banco conectado. A primeira sincronização pode levar alguns minutos.')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('422 em links.N.account_id mostra o erro na linha certa', async () => {
    linkMutateAsync.mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', null, { 'links.1.account_id': ['Esta conta já está conectada a um banco.'] }),
    )
    renderDialog({
      connection: connection([
        providerAccount({ external_id: 'ext-1', name: 'Conta A' }),
        providerAccount({ external_id: 'ext-2', name: 'Conta B' }),
      ]),
    })

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    expect(await screen.findByText('Esta conta já está conectada a um banco.')).toBeInTheDocument()
  })

  it('409 account_no_longer_linkable avisa e invalida as contas (sem fechar)', async () => {
    const error = new ApiError(409, 'Uma das contas escolhidas não está mais disponível para vínculo.', 'account_no_longer_linkable')
    linkMutateAsync.mockRejectedValue(error)
    const onOpenChange = vi.fn()
    const { client } = renderDialog({ connection: connection([providerAccount()]), onOpenChange })
    client.setQueryData(queryKeys.accounts(false), [])

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith(error.message))
    expect(client.getQueryState(queryKeys.accounts(false))?.isInvalidated).toBe(true)
    expect(onOpenChange).not.toHaveBeenCalled()
  })

  it('409 connection_not_pending_link avisa, invalida conexões e fecha', async () => {
    linkMutateAsync.mockRejectedValue(new ApiError(409, 'Esta conexão não está esperando vínculo.', 'connection_not_pending_link'))
    const onOpenChange = vi.fn()
    const { client } = renderDialog({ connection: connection([providerAccount()]), onOpenChange })
    client.setQueryData(queryKeys.bankConnections(), [])

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Este banco já foi vinculado.'))
    expect(client.getQueryState(queryKeys.bankConnections())?.isInvalidated).toBe(true)
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('fechar sem vincular não chama a API', () => {
    const onOpenChange = vi.fn()
    renderDialog({ connection: connection([providerAccount()]), onOpenChange })

    fireEvent.click(screen.getByRole('button', { name: 'Fechar' }))

    expect(linkMutateAsync).not.toHaveBeenCalled()
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })
})
