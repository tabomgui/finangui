import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'

const { GET, POST } = vi.hoisted(() => ({ GET: vi.fn(), POST: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, POST } }
})

const {
  useTransferSuggestions,
  useDetectTransfers,
  useAcceptSuggestion,
  useDismissSuggestion,
  useLinkTransfer,
  useUnlinkTransfer,
} = await import('./transfer-suggestions')

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  GET.mockReset()
  POST.mockReset()
})

describe('useTransferSuggestions', () => {
  it('busca a primeira página em GET /transfer-suggestions sem cursor', async () => {
    GET.mockResolvedValue({ data: { data: [], meta: { next_cursor: null } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useTransferSuggestions(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(result.current.data).toBeDefined())
    })

    expect(GET).toHaveBeenCalledWith('/transfer-suggestions', { params: { query: { per_page: 20 } } })
  })
})

describe('useDetectTransfers', () => {
  it('chama o detect e invalida sugestões e o razão', async () => {
    POST.mockResolvedValue({ data: { data: { linked: 1, suggested: 2 } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useDetectTransfers(), { wrapper: wrapper(client) })
    let value: { linked: number; suggested: number } | undefined
    await act(async () => {
      value = await result.current.mutateAsync()
    })

    expect(POST).toHaveBeenCalledWith('/transfer-suggestions/detect')
    expect(value).toEqual({ linked: 1, suggested: 2 })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})

describe('useAcceptSuggestion', () => {
  it('ao ligar, invalida o razão; em qualquer caso, invalida sugestões (o backend já excluiu a desatualizada em 409)', async () => {
    POST.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useAcceptSuggestion(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9)
    })

    expect(POST).toHaveBeenCalledWith('/transfer-suggestions/{suggestion}/accept', { params: { path: { suggestion: 9 } } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})

describe('useDismissSuggestion', () => {
  it('descarta e invalida só as sugestões', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useDismissSuggestion(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9)
    })

    expect(POST).toHaveBeenCalledWith('/transfer-suggestions/{suggestion}/dismiss', { params: { path: { suggestion: 9 } } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(false)
  })
})

describe('useLinkTransfer', () => {
  it('liga e invalida sugestões e o razão', async () => {
    POST.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useLinkTransfer(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ out_transaction_id: 1, in_transaction_id: 2 })
    })

    expect(POST).toHaveBeenCalledWith('/transfers/link', { body: { out_transaction_id: 1, in_transaction_id: 2 } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})

describe('useUnlinkTransfer', () => {
  it('desfaz e invalida sugestões e o razão', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useUnlinkTransfer(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync('uuid-1')
    })

    expect(POST).toHaveBeenCalledWith('/transfers/{transfer}/unlink', { params: { path: { transfer: 'uuid-1' } } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})
