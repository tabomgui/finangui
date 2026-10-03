import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BankConnection } from '@/api/types'
import { ConnectBankButton } from './connect-bank-button'

const connectTokenMutateAsync = vi.fn()
const createConnectionMutateAsync = vi.fn()
const linkAccountsMutateAsync = vi.fn()

vi.mock('@/api/queries/bank-connections', () => ({
  useConnectToken: () => ({ mutateAsync: connectTokenMutateAsync, isPending: false }),
  useCreateConnection: () => ({ mutateAsync: createConnectionMutateAsync, isPending: false }),
  useLinkAccounts: () => ({ mutateAsync: linkAccountsMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [] }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('react-pluggy-connect', () => ({
  PluggyConnect: (props: {
    onSuccess: (data: { item: { id: string } }) => void
    onClose: () => void
    onError: (error: { message: string }) => void
  }) => (
    <div>
      <button onClick={() => props.onSuccess({ item: { id: 'item-123' } })}>mock-success</button>
      <button onClick={() => props.onClose()}>mock-close</button>
      <button onClick={() => props.onError({ message: 'Falha ao conectar.' })}>mock-error</button>
    </div>
  ),
}))

const { toast } = await import('sonner')

function connectionResult(): { connection: BankConnection; provider_accounts: BankConnection['pending_accounts'] } {
  const pendingAccounts: BankConnection['pending_accounts'] = [
    {
      external_id: 'ext-1',
      name: 'Conta corrente',
      number: '1234',
      kind: 'checking',
      currency: 'BRL',
      balance: 15000,
      suggested_account_id: null,
    },
  ]
  return {
    connection: {
      id: 42,
      provider: 'pluggy',
      status: 'pending_link',
      institution_name: 'Banco Fictício',
      institution_logo_url: null,
      last_synced_at: null,
      last_error: null,
      accounts: [],
      pending_accounts: pendingAccounts,
    },
    provider_accounts: pendingAccounts,
  }
}

beforeEach(() => {
  connectTokenMutateAsync.mockReset().mockResolvedValue('connect-token-1')
  createConnectionMutateAsync.mockReset().mockResolvedValue(connectionResult())
  linkAccountsMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('ConnectBankButton', () => {
  it('fluxo feliz: busca o token, abre o widget e, ao conectar, cria a conexão e abre o vínculo', async () => {
    render(<ConnectBankButton />)

    fireEvent.click(screen.getByRole('button', { name: 'Conectar banco' }))

    expect(await screen.findByText('mock-success')).toBeInTheDocument()
    expect(connectTokenMutateAsync).toHaveBeenCalled()

    fireEvent.click(screen.getByText('mock-success'))

    await vi.waitFor(() => expect(createConnectionMutateAsync).toHaveBeenCalledWith('item-123'))
    expect(await screen.findByText('Vincular contas')).toBeInTheDocument()
    expect(screen.getByText('Conta corrente')).toBeInTheDocument()
  })

  it('fechar o widget sem sucesso não chama a API de criar conexão', async () => {
    render(<ConnectBankButton />)

    fireEvent.click(screen.getByRole('button', { name: 'Conectar banco' }))
    fireEvent.click(await screen.findByText('mock-close'))

    expect(createConnectionMutateAsync).not.toHaveBeenCalled()
    expect(screen.queryByText('mock-success')).not.toBeInTheDocument()
  })

  it('erro do widget mostra toast e não chama a API de criar conexão', async () => {
    render(<ConnectBankButton />)

    fireEvent.click(screen.getByRole('button', { name: 'Conectar banco' }))
    fireEvent.click(await screen.findByText('mock-error'))

    expect(createConnectionMutateAsync).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('Falha ao conectar.')
  })
})
