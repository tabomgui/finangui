import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BankConnection } from '@/api/types'
import { ConnectBankButton } from './connect-bank-button'

function renderButton() {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <ConnectBankButton />
    </QueryClientProvider>,
  )
}

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

type MockProps = {
  onSuccess: (data: { item: { id: string } }) => void
  onClose: () => void
  onError: (error: { message: string }) => void
}

const instances: MockProps[] = []

vi.mock('pluggy-connect-sdk', () => ({
  // Mock da classe real (sem iframe nem zoid): grava as props do construtor para os testes
  // disparar cada callback na mão.
  PluggyConnect: class {
    props: MockProps
    constructor(props: MockProps) {
      this.props = props
      instances.push(props)
    }
    init() {
      return Promise.resolve()
    }
    destroy() {
      return Promise.resolve()
    }
  },
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
      unlinked_accounts: [],
    },
    provider_accounts: pendingAccounts,
  }
}

beforeEach(() => {
  instances.length = 0
  connectTokenMutateAsync.mockReset().mockResolvedValue({ token: 'connect-token-1', itemId: undefined })
  createConnectionMutateAsync.mockReset().mockResolvedValue(connectionResult())
  linkAccountsMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('ConnectBankButton', () => {
  it('fluxo feliz: busca o token, abre o widget e, ao conectar, cria a conexão e abre o vínculo', async () => {
    renderButton()

    fireEvent.click(screen.getByRole('button', { name: 'Conectar banco' }))

    await vi.waitFor(() => expect(instances).toHaveLength(1))
    expect(connectTokenMutateAsync).toHaveBeenCalled()

    instances[0].onSuccess({ item: { id: 'item-123' } })

    await vi.waitFor(() => expect(createConnectionMutateAsync).toHaveBeenCalledWith('item-123'))
    expect(await screen.findByText('Vincular contas')).toBeInTheDocument()
    expect(screen.getByText('Conta corrente')).toBeInTheDocument()
  })

  it('fechar o widget sem sucesso não chama a API de criar conexão', async () => {
    renderButton()

    fireEvent.click(screen.getByRole('button', { name: 'Conectar banco' }))
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    instances[0].onClose()

    expect(createConnectionMutateAsync).not.toHaveBeenCalled()
  })

  it('erro do widget mostra toast, mantém o widget aberto e não chama a API de criar conexão', async () => {
    renderButton()

    fireEvent.click(screen.getByRole('button', { name: 'Conectar banco' }))
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    instances[0].onError({ message: 'Falha ao conectar.' })

    expect(createConnectionMutateAsync).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('Falha ao conectar.')
    // só mais uma instância apareceria se o widget tivesse fechado e reaberto; onError não fecha.
    expect(instances).toHaveLength(1)
  })

  it('desabilita o botão enquanto o widget está aberto', async () => {
    renderButton()

    const button = screen.getByRole('button', { name: 'Conectar banco' })
    expect(button).not.toBeDisabled()

    fireEvent.click(button)
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    expect(button).toBeDisabled()

    instances[0].onClose()
    await vi.waitFor(() => expect(button).not.toBeDisabled())
  })
})
