import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { BankConnection } from '@/api/types'

const { GET, POST, DELETE } = vi.hoisted(() => ({ GET: vi.fn(), POST: vi.fn(), DELETE: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, POST, DELETE } }
})

const { useBankConnections, useDisconnect, useLinkAccounts, useSyncConnection } = await import('./bank-connections')

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function connection(overrides: Partial<BankConnection>): BankConnection {
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

beforeEach(() => {
  GET.mockReset()
  POST.mockReset()
  DELETE.mockReset()
})

describe('useBankConnections', () => {
  it('busca a lista em GET /bank-connections', async () => {
    GET.mockResolvedValue({ data: { data: [connection({ id: 1 })] }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useBankConnections(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(result.current.data).toHaveLength(1))
    })

    expect(GET).toHaveBeenCalledWith('/bank-connections')
  })

  it('refaz a cada 5s enquanto uma conexão tem sync pedido recentemente, e para quando last_synced_at muda', async () => {
    GET.mockResolvedValue({ data: { data: [connection({ id: 42, last_synced_at: null })] }, error: undefined, response: { ok: true } })
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result: sync } = renderHook(() => useSyncConnection(), { wrapper: wrapper(client) })
    const { result: list } = renderHook(() => useBankConnections(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(list.current.data).toHaveLength(1))
    })

    await act(async () => {
      await sync.current.mutateAsync(42)
    })

    const options = client.getQueryCache().find({ queryKey: queryKeys.bankConnections() })?.options as unknown as {
      refetchInterval: (query: { state: { data: unknown } }) => number | false
    }
    const refetchInterval = options.refetchInterval

    expect(refetchInterval({ state: { data: [connection({ id: 42, last_synced_at: null })] } })).toBe(5_000)
    expect(refetchInterval({ state: { data: [connection({ id: 42, last_synced_at: '2026-10-03T12:00:00Z' })] } })).toBe(false)
  })
})

describe('useLinkAccounts', () => {
  it('vincula contas e invalida conexões e o razão', async () => {
    POST.mockResolvedValue({ data: { data: connection({ id: 7 }) }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankConnections(), [connection({ id: 7 })])
    client.setQueryData(['transactions'], [])

    const { result } = renderHook(() => useLinkAccounts(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ id: 7, body: { links: [{ external_id: 'acc-1', account_id: null }] } })
    })

    expect(client.getQueryState(queryKeys.bankConnections())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})

describe('useDisconnect', () => {
  it('desconecta e invalida conexões e o razão (as contas voltam a ser manuais)', async () => {
    DELETE.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankConnections(), [connection({ id: 3 })])
    client.setQueryData(['accounts'], [])

    const { result } = renderHook(() => useDisconnect(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(3)
    })

    expect(DELETE).toHaveBeenCalledWith('/bank-connections/{connection}', { params: { path: { connection: 3 } } })
    expect(client.getQueryState(queryKeys.bankConnections())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['accounts'])?.isInvalidated).toBe(true)
  })
})
