import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { BankConnection } from '@/api/types'
import { ConnectionCard } from './connection-card'

const syncMutateAsync = vi.fn()
const disconnectMutateAsync = vi.fn()

vi.mock('@/api/queries/bank-connections', () => ({
  useSyncConnection: () => ({ mutateAsync: syncMutateAsync, isPending: false }),
  useDisconnect: () => ({ mutateAsync: disconnectMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

beforeEach(() => {
  syncMutateAsync.mockReset().mockResolvedValue(undefined)
  disconnectMutateAsync.mockReset().mockResolvedValue(undefined)
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
    ...overrides,
  }
}

async function openMenu(name: string) {
  const trigger = screen.getByRole('button', { name })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
}

describe('ConnectionCard', () => {
  it('mostra nome, status, sincronização e erro', () => {
    render(
      <ConnectionCard
        connection={connection({ institution_name: 'Nubank', status: 'error', last_error: 'Falha ao atualizar.' })}
      />,
    )

    expect(screen.getByText('Nubank')).toBeInTheDocument()
    expect(screen.getByText('Erro na sincronização')).toBeInTheDocument()
    expect(screen.getByText('Nunca sincronizado')).toBeInTheDocument()
    expect(screen.getByText('Falha ao atualizar.')).toBeInTheDocument()
  })

  it('mostra as contas vinculadas com o saldo do app', () => {
    render(
      <ConnectionCard
        connection={connection({
          accounts: [{ id: 10, name: 'Conta corrente', type: 'checking', balance: 15000, provider_balance: 15000 }],
        })}
      />,
    )

    expect(screen.getByText('Conta corrente')).toBeInTheDocument()
    expect(screen.getByText(/150,00/)).toBeInTheDocument()
  })

  it('mostra "Banco informa" quando o saldo do banco difere, só para conta que não é cartão', () => {
    render(
      <ConnectionCard
        connection={connection({
          accounts: [
            { id: 10, name: 'Conta corrente', type: 'checking', balance: 15000, provider_balance: 16000 },
            { id: 11, name: 'Cartão', type: 'credit_card', balance: -5000, provider_balance: -8000 },
          ],
        })}
      />,
    )

    expect(screen.getByText(/Banco informa/)).toBeInTheDocument()
    expect(screen.getAllByText(/Banco informa/)).toHaveLength(1)
  })

  it('aciona a sincronização pelo menu', async () => {
    render(<ConnectionCard connection={connection({ id: 9 })} />)

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Sincronizar agora' }))

    expect(syncMutateAsync).toHaveBeenCalledWith(9)
  })

  it('mostra aviso específico quando já há sincronização em andamento (409)', async () => {
    syncMutateAsync.mockRejectedValue(new ApiError(409, 'Esta conexão já está sincronizando.', 'connection_sync_in_progress'))
    render(<ConnectionCard connection={connection({ id: 9 })} />)

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Sincronizar agora' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Sincronização em andamento.'))
  })

  it('chama onReconnect pelo menu', async () => {
    const onReconnect = vi.fn()
    const target = connection({ id: 5, status: 'needs_reauth' })
    render(<ConnectionCard connection={target} onReconnect={onReconnect} />)

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Reconectar' }))

    expect(onReconnect).toHaveBeenCalledWith(target)
  })

  it('mostra "Vincular contas" para conexão pending_link e aciona onLinkAccounts', () => {
    const onLinkAccounts = vi.fn()
    const target = connection({ id: 7, status: 'pending_link' })
    render(<ConnectionCard connection={target} onLinkAccounts={onLinkAccounts} />)

    fireEvent.click(screen.getByRole('button', { name: 'Vincular contas' }))

    expect(onLinkAccounts).toHaveBeenCalledWith(target)
  })

  it('não mostra "Vincular contas" para conexão já ativa', () => {
    render(<ConnectionCard connection={connection({ status: 'active' })} />)

    expect(screen.queryByRole('button', { name: 'Vincular contas' })).not.toBeInTheDocument()
  })

  it('desconectar abre confirmação e, ao confirmar, chama a mutação', async () => {
    render(<ConnectionCard connection={connection({ id: 3 })} />)

    await openMenu('Ações da conexão Banco Fictício')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Desconectar' }))

    expect(await screen.findByText('As contas continuam no app com todo o histórico, como contas manuais.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Desconectar' }))

    await vi.waitFor(() => expect(disconnectMutateAsync).toHaveBeenCalledWith(3))
  })
})
