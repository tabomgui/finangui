import { fireEvent, render, screen } from '@testing-library/react'
import type { ComponentProps } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Account, BankConnection } from '@/api/types'
import { ConnectionCard } from './connection-card'

const syncMutateAsync = vi.fn()
const disconnectMutateAsync = vi.fn()
const updateAccountMutateAsync = vi.fn()
const deleteAccountMutateAsync = vi.fn()

vi.mock('@/api/queries/bank-connections', () => ({
  useSyncConnection: () => ({ mutateAsync: syncMutateAsync, isPending: false }),
  useDisconnect: () => ({ mutateAsync: disconnectMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useUpdateAccount: () => ({ mutateAsync: updateAccountMutateAsync, isPending: false }),
  useDeleteAccount: () => ({ mutateAsync: deleteAccountMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

beforeEach(() => {
  syncMutateAsync.mockReset().mockResolvedValue(undefined)
  disconnectMutateAsync.mockReset().mockResolvedValue(undefined)
  updateAccountMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteAccountMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

function connection(overrides: Partial<BankConnection> = {}): BankConnection {
  return {
    id: 1,
    provider: 'pluggy',
    status: 'active',
    institution_name: 'Banco Fictício',
    institution_logo_url: null,
    last_synced_at: null,
    last_error: null,
    accounts: [],
    pending_accounts: [],
    unlinked_accounts: [],
    ...overrides,
  }
}

function account(overrides: Partial<Account> = {}): Account {
  return {
    id: 10,
    name: 'Nubank',
    type: 'checking',
    currency: 'BRL',
    opening_balance: 0,
    balance: 15000,
    credit_limit: null,
    closing_day: null,
    due_day: null,
    last_four: null,
    color: null,
    icon: null,
    is_archived: false,
    connection_id: 1,
    provider_balance: 15000,
    provider_synced_at: null,
    ...overrides,
  }
}

function renderCard(props: Partial<ComponentProps<typeof ConnectionCard>> = {}) {
  return render(
    <MemoryRouter>
      <ConnectionCard connection={connection()} accounts={[]} onEditAccount={vi.fn()} {...props} />
    </MemoryRouter>,
  )
}

async function openMenu(name: string) {
  const trigger = screen.getByRole('button', { name })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
}

describe('ConnectionCard', () => {
  it('mostra nome, status, sincronização e erro', () => {
    renderCard({ connection: connection({ institution_name: 'Nubank', status: 'error', last_error: 'Falha ao atualizar.' }) })

    expect(screen.getByText('Nubank')).toBeInTheDocument()
    expect(screen.getByText('Erro na sincronização')).toBeInTheDocument()
    expect(screen.getByText('Nunca sincronizado')).toBeInTheDocument()
    expect(screen.getByText('Falha ao atualizar.')).toBeInTheDocument()
  })

  it('o logo é decorativo (alt vazio) — o nome do banco já aparece em texto', () => {
    const { container } = renderCard({
      connection: connection({ institution_name: 'Nubank', institution_logo_url: 'https://cdn.example.com/logo.png' }),
    })

    // alt="" tira a imagem do papel "img" da árvore de acessibilidade de propósito (decorativa);
    // por isso a busca é pelo elemento direto, não por getByRole.
    expect(container.querySelector('img')).toHaveAttribute('alt', '')
  })

  it('mostra as contas vinculadas com o saldo do app', () => {
    renderCard({ accounts: [account({ id: 10, name: 'Nubank', balance: 15000, provider_balance: 15000 })] })

    expect(screen.getByText('Nubank')).toBeInTheDocument()
    expect(screen.getByText(/150,00/)).toBeInTheDocument()
  })

  it('mostra "Banco informa" quando o saldo do banco difere, só para conta que não é cartão', () => {
    renderCard({
      accounts: [
        account({ id: 10, name: 'Nubank', type: 'checking', balance: 15000, provider_balance: 16000 }),
        account({ id: 11, name: 'Cartão', type: 'credit_card', balance: -5000, provider_balance: -8000 }),
      ],
    })

    expect(screen.getByText(/Banco informa/)).toBeInTheDocument()
    expect(screen.getAllByText(/Banco informa/)).toHaveLength(1)
  })

  it('edita uma conta vinculada pelo menu da linha', async () => {
    const onEditAccount = vi.fn()
    const target = account({ id: 10, name: 'Nubank' })
    renderCard({ accounts: [target], onEditAccount })

    await openMenu('Ações da conta Nubank')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Editar' }))

    expect(onEditAccount).toHaveBeenCalledWith(target)
  })

  it('não oferece "Importar extrato" para uma conta vinculada', async () => {
    renderCard({ accounts: [account({ id: 10, name: 'Nubank' })] })

    await openMenu('Ações da conta Nubank')

    expect(screen.queryByRole('menuitem', { name: 'Importar extrato' })).not.toBeInTheDocument()
  })

  it('aciona a sincronização pelo menu', async () => {
    renderCard({ connection: connection({ id: 9 }) })

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Sincronizar agora' }))

    expect(syncMutateAsync).toHaveBeenCalledWith(9)
  })

  it('mostra aviso específico quando já há sincronização em andamento (409)', async () => {
    syncMutateAsync.mockRejectedValue(new ApiError(409, 'Esta conexão já está sincronizando.', 'connection_sync_in_progress'))
    renderCard({ connection: connection({ id: 9 }) })

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Sincronizar agora' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Sincronização em andamento.'))
  })

  it('chama onReconnect pelo menu', async () => {
    const onReconnect = vi.fn()
    const target = connection({ id: 5, status: 'needs_reauth' })
    renderCard({ connection: target, onReconnect })

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Reconectar' }))

    expect(onReconnect).toHaveBeenCalledWith(target)
  })

  it('desabilita "Reconectar" quando reconnectDisabled', async () => {
    renderCard({ connection: connection({ id: 5, status: 'needs_reauth' }), reconnectDisabled: true })

    await openMenu('Ações da conexão Banco Fictício')

    expect(await screen.findByRole('menuitem', { name: 'Reconectar' })).toHaveAttribute('aria-disabled', 'true')
  })

  it('mostra "Vincular contas" para conexão pending_link com contas pendentes e aciona onLinkAccounts', () => {
    const onLinkAccounts = vi.fn()
    const target = connection({
      id: 7,
      status: 'pending_link',
      pending_accounts: [
        { external_id: 'ext-1', name: 'Conta', number: null, kind: 'checking', currency: 'BRL', balance: 0, suggested_account_id: null },
      ],
    })
    renderCard({ connection: target, onLinkAccounts })

    fireEvent.click(screen.getByRole('button', { name: 'Vincular contas' }))

    expect(onLinkAccounts).toHaveBeenCalledWith(target)
  })

  it('não mostra "Vincular contas" quando pending_accounts está vazio', () => {
    renderCard({ connection: connection({ status: 'pending_link', pending_accounts: [] }) })

    expect(screen.queryByRole('button', { name: 'Vincular contas' })).not.toBeInTheDocument()
  })

  it('não mostra "Vincular contas" para conexão já ativa', () => {
    renderCard({ connection: connection({ status: 'active' }) })

    expect(screen.queryByRole('button', { name: 'Vincular contas' })).not.toBeInTheDocument()
  })

  it('esconde o menu de ações (sincronizar/reconectar) para conexão pending_link', () => {
    renderCard({ connection: connection({ status: 'pending_link' }) })

    expect(screen.queryByRole('button', { name: /Ações da conexão/ })).not.toBeInTheDocument()
  })

  it('esconde o menu de ações quando o banking está desligado', () => {
    renderCard({ connection: connection({ status: 'active' }), bankingEnabled: false })

    expect(screen.queryByRole('button', { name: /Ações da conexão/ })).not.toBeInTheDocument()
  })

  it('desconectar abre confirmação e, ao confirmar, chama a mutação', async () => {
    renderCard({ connection: connection({ id: 3 }) })

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Desconectar' }))

    expect(await screen.findByText('As contas continuam no app com todo o histórico, como contas manuais.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Desconectar' }))

    await vi.waitFor(() => expect(disconnectMutateAsync).toHaveBeenCalledWith(3))
  })
})
