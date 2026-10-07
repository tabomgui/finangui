import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { BankConnection } from '@/api/types'

const { GET, POST, DELETE } = vi.hoisted(() => ({ GET: vi.fn(), POST: vi.fn(), DELETE: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, POST, DELETE } }
})

const { useBankConnections, useConnectToken, useDisconnect, useLinkAccounts, useMarkReconnected, useSyncConnection } =
  await import('./bank-connections')

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
    unlinked_accounts: [],
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

  it('também para de pedir quando o status muda, mesmo sem last_synced_at mudar, e invalida o razão', async () => {
    GET.mockResolvedValue({
      data: { data: [connection({ id: 9, status: 'needs_reauth', last_synced_at: '2026-10-01T00:00:00Z' })] },
      error: undefined,
      response: { ok: true },
    })
    POST.mockResolvedValue({ data: { data: connection({ id: 9, status: 'needs_reauth' }) }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(['transactions'], [])

    const { result: reconnect } = renderHook(() => useMarkReconnected(), { wrapper: wrapper(client) })
    const { result: list } = renderHook(() => useBankConnections(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(list.current.data).toHaveLength(1))
    })

    await act(async () => {
      await reconnect.current.mutateAsync({ id: 9, itemId: 'item-9' })
    })

    const options = client.getQueryCache().find({ queryKey: queryKeys.bankConnections() })?.options as unknown as {
      refetchInterval: (query: { state: { data: unknown } }) => number | false
    }
    const refetchInterval = options.refetchInterval

    // Mesma last_synced_at da marcação, mas status virou active (a reconexão já devolve isso) —
    // já é sinal suficiente de que o sync fechou o ciclo.
    expect(refetchInterval({ state: { data: [connection({ id: 9, status: 'active', last_synced_at: '2026-10-01T00:00:00Z' })] } })).toBe(
      false,
    )
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })

  it('cada QueryClient tem seu próprio controle de pendências (isolamento de teste)', async () => {
    GET.mockResolvedValue({ data: { data: [connection({ id: 42, last_synced_at: null })] }, error: undefined, response: { ok: true } })
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })

    const clientA = new QueryClient()
    const { result: syncA } = renderHook(() => useSyncConnection(), { wrapper: wrapper(clientA) })
    const { result: listA } = renderHook(() => useBankConnections(), { wrapper: wrapper(clientA) })
    await act(async () => {
      await vi.waitFor(() => expect(listA.current.data).toHaveLength(1))
    })
    await act(async () => {
      await syncA.current.mutateAsync(42)
    })

    const clientB = new QueryClient()
    const { result: listB } = renderHook(() => useBankConnections(), { wrapper: wrapper(clientB) })
    await act(async () => {
      await vi.waitFor(() => expect(listB.current.data).toHaveLength(1))
    })

    const optionsB = clientB.getQueryCache().find({ queryKey: queryKeys.bankConnections() })?.options as unknown as {
      refetchInterval: (query: { state: { data: unknown } }) => number | false
    }

    // clientB nunca pediu sync; não deve herdar o "pendente" marcado em clientA.
    expect(optionsB.refetchInterval({ state: { data: [connection({ id: 42, last_synced_at: null })] } })).toBe(false)
  })

  it('a cada tique pendente, invalida o histórico de sync da conexão (a run criada pode ter aparecido só agora)', async () => {
    GET.mockResolvedValue({ data: { data: [connection({ id: 42, last_synced_at: null })] }, error: undefined, response: { ok: true } })
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankSyncRuns(42), { pages: [], pageParams: [] })

    const { result: sync } = renderHook(() => useSyncConnection(), { wrapper: wrapper(client) })
    const { result: list } = renderHook(() => useBankConnections(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(list.current.data).toHaveLength(1))
    })

    await act(async () => {
      await sync.current.mutateAsync(42)
    })
    // onSuccess do próprio useSyncConnection já invalida uma vez; zera para provar que o
    // predicado do polling de useBankConnections invalida de novo, por conta própria.
    client.getQueryCache().find({ queryKey: queryKeys.bankSyncRuns(42) })?.setState({ isInvalidated: false })

    const options = client.getQueryCache().find({ queryKey: queryKeys.bankConnections() })?.options as unknown as {
      refetchInterval: (query: { state: { data: unknown } }) => number | false
    }

    expect(options.refetchInterval({ state: { data: [connection({ id: 42, last_synced_at: null })] } })).toBe(5_000)
    expect(client.getQueryState(queryKeys.bankSyncRuns(42))?.isInvalidated).toBe(true)
  })

  it('quando o sync pendente termina (last_synced_at muda), também invalida o histórico de sync da conexão', async () => {
    GET.mockResolvedValue({ data: { data: [connection({ id: 42, last_synced_at: null })] }, error: undefined, response: { ok: true } })
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankSyncRuns(42), { pages: [], pageParams: [] })

    const { result: sync } = renderHook(() => useSyncConnection(), { wrapper: wrapper(client) })
    const { result: list } = renderHook(() => useBankConnections(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(list.current.data).toHaveLength(1))
    })

    await act(async () => {
      await sync.current.mutateAsync(42)
    })
    client.getQueryCache().find({ queryKey: queryKeys.bankSyncRuns(42) })?.setState({ isInvalidated: false })

    const options = client.getQueryCache().find({ queryKey: queryKeys.bankConnections() })?.options as unknown as {
      refetchInterval: (query: { state: { data: unknown } }) => number | false
    }

    options.refetchInterval({ state: { data: [connection({ id: 42, last_synced_at: '2026-10-03T12:00:00Z' })] } })

    expect(client.getQueryState(queryKeys.bankSyncRuns(42))?.isInvalidated).toBe(true)
  })

  it('para de pedir depois de 2 minutos mesmo sem mudança (expiração)', async () => {
    vi.useFakeTimers()
    try {
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
      const unchanged = [connection({ id: 42, last_synced_at: null })]

      expect(options.refetchInterval({ state: { data: unchanged } })).toBe(5_000)

      vi.advanceTimersByTime(2 * 60_000 + 1)

      expect(options.refetchInterval({ state: { data: unchanged } })).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('useConnectToken', () => {
  it('devolve { token, itemId } com item_id (modo atualização)', async () => {
    POST.mockResolvedValue({ data: { data: { connect_token: 'tok-1', item_id: 'item-9' } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useConnectToken(), { wrapper: wrapper(client) })
    let value: { token: string; itemId?: string } | undefined
    await act(async () => {
      value = await result.current.mutateAsync({ connection_id: 9 })
    })

    expect(value).toEqual({ token: 'tok-1', itemId: 'item-9' })
  })

  it('devolve itemId undefined sem connection_id (conexão nova)', async () => {
    POST.mockResolvedValue({ data: { data: { connect_token: 'tok-1' } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useConnectToken(), { wrapper: wrapper(client) })
    let value: { token: string; itemId?: string } | undefined
    await act(async () => {
      value = await result.current.mutateAsync({})
    })

    expect(value).toEqual({ token: 'tok-1', itemId: undefined })
  })
})

describe('useMarkReconnected', () => {
  it('envia item_id e invalida conexões', async () => {
    POST.mockResolvedValue({ data: { data: connection({ id: 9, status: 'active' }) }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankConnections(), [connection({ id: 9, status: 'needs_reauth' })])

    const { result } = renderHook(() => useMarkReconnected(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ id: 9, itemId: 'item-9' })
    })

    expect(POST).toHaveBeenCalledWith('/bank-connections/{connection}/reconnected', {
      params: { path: { connection: 9 } },
      body: { item_id: 'item-9' },
    })
    expect(client.getQueryState(queryKeys.bankConnections())?.isInvalidated).toBe(true)
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

afterEach(() => {
  vi.useRealTimers()
})
