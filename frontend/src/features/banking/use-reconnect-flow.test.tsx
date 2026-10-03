import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BankConnection } from '@/api/types'
import { useReconnectFlow } from './use-reconnect-flow'

const connectTokenMutateAsync = vi.fn()
const markReconnectedMutateAsync = vi.fn()

vi.mock('@/api/queries/bank-connections', () => ({
  useConnectToken: () => ({ mutateAsync: connectTokenMutateAsync, isPending: false }),
  useMarkReconnected: () => ({ mutateAsync: markReconnectedMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

type MockProps = {
  updateItem?: string
  onSuccess: (data: { item: { id: string } }) => void
  onClose: () => void
  onError: (error: { message: string }) => void
}

const instances: MockProps[] = []

vi.mock('pluggy-connect-sdk', () => ({
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

function connection(overrides: Partial<BankConnection> = {}): BankConnection {
  return {
    id: 9,
    provider: 'pluggy',
    status: 'needs_reauth',
    institution_name: 'Banco Fictício',
    institution_logo_url: null,
    last_synced_at: null,
    last_error: 'Reconexão necessária.',
    accounts: [],
    pending_accounts: [],
    unlinked_accounts: [],
    ...overrides,
  }
}

function Harness() {
  const { reconnect, widget, isPending } = useReconnectFlow()
  return (
    <div>
      <button disabled={isPending} onClick={() => reconnect(connection())}>
        reconnect
      </button>
      {widget}
    </div>
  )
}

beforeEach(() => {
  instances.length = 0
  connectTokenMutateAsync.mockReset().mockResolvedValue({ token: 'tok-1', itemId: 'item-9' })
  markReconnectedMutateAsync.mockReset().mockResolvedValue(connection({ status: 'active' }))
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('useReconnectFlow', () => {
  it('pede o token com connection_id e abre o widget com updateItem', async () => {
    render(<Harness />)

    fireEvent.click(screen.getByRole('button', { name: 'reconnect' }))

    await vi.waitFor(() => expect(instances).toHaveLength(1))
    expect(connectTokenMutateAsync).toHaveBeenCalledWith({ connection_id: 9 })
    expect(instances[0].updateItem).toBe('item-9')
  })

  it('sucesso com o mesmo item marca reconectada e avisa', async () => {
    render(<Harness />)
    fireEvent.click(screen.getByRole('button', { name: 'reconnect' }))
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    instances[0].onSuccess({ item: { id: 'item-9' } })

    await vi.waitFor(() => expect(markReconnectedMutateAsync).toHaveBeenCalledWith({ id: 9, itemId: 'item-9' }))
    expect(toast.success).toHaveBeenCalledWith('Banco reconectado.')
  })

  it('sucesso com item diferente do pedido avisa e não marca reconectada', async () => {
    render(<Harness />)
    fireEvent.click(screen.getByRole('button', { name: 'reconnect' }))
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    instances[0].onSuccess({ item: { id: 'item-outro' } })

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalled())
    expect(markReconnectedMutateAsync).not.toHaveBeenCalled()
  })

  it('fechar sem sucesso não chama a API', async () => {
    render(<Harness />)
    fireEvent.click(screen.getByRole('button', { name: 'reconnect' }))
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    instances[0].onClose()

    expect(markReconnectedMutateAsync).not.toHaveBeenCalled()
  })

  it('desabilita o gatilho enquanto o widget está aberto', async () => {
    render(<Harness />)
    const button = screen.getByRole('button', { name: 'reconnect' })
    expect(button).not.toBeDisabled()

    fireEvent.click(button)
    await vi.waitFor(() => expect(instances).toHaveLength(1))

    expect(button).toBeDisabled()
  })
})
