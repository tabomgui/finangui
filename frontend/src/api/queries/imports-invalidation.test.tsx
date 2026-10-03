import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'

const { POST, DELETE } = vi.hoisted(() => ({ POST: vi.fn(), DELETE: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, POST, DELETE } }
})

const { useCancelImport, useConfirmImport, useRevertImport } = await import('./imports')

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function seedDetailAndList(client: QueryClient, id: number) {
  client.setQueryData(queryKeys.importBatch(id), { batch: { id, status: 'pending' }, rows: [], summary: {} })
  client.setQueryData(queryKeys.importBatches(), [{ id, status: 'pending' }])
}

describe('useCancelImport', () => {
  it('remove o detalhe do cache (não só invalida) e invalida a listagem, sem tocar no ledger', async () => {
    DELETE.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    seedDetailAndList(client, 9)

    const { result } = renderHook(() => useCancelImport(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9)
    })

    // `removeQueries`, não `invalidateQueries`: a query do detalhe não deve nem continuar em
    // cache para uma tela de prévia que está de saída tentar refetchar e levar um 404.
    expect(client.getQueryCache().find({ queryKey: queryKeys.importBatch(9) })).toBeUndefined()
    expect(client.getQueryState(queryKeys.importBatches())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])).toBeUndefined()
  })
})

describe('useConfirmImport', () => {
  it('remove o detalhe do cache e invalida a listagem e o ledger', async () => {
    POST.mockResolvedValue({
      data: { data: { batch: { id: 9, status: 'completed' }, rows: [], summary: {} } },
      error: undefined,
      response: { ok: true },
    })
    const client = new QueryClient()
    seedDetailAndList(client, 9)
    client.setQueryData(['transactions', 'list', {}], { pages: [] })

    const { result } = renderHook(() => useConfirmImport(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ id: 9, body: {} })
    })

    expect(client.getQueryCache().find({ queryKey: queryKeys.importBatch(9) })).toBeUndefined()
    expect(client.getQueryState(queryKeys.importBatches())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions', 'list', {}])?.isInvalidated).toBe(true)
  })
})

describe('useRevertImport', () => {
  it('invalida listagem e ledger quando a reversão dá certo', async () => {
    POST.mockResolvedValue({
      data: { data: { batch: { id: 9, status: 'reverted' }, rows: [], summary: {} } },
      error: undefined,
      response: { ok: true },
    })
    const client = new QueryClient()
    seedDetailAndList(client, 9)
    client.setQueryData(['transactions', 'list', {}], { pages: [] })

    const { result } = renderHook(() => useRevertImport(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9)
    })

    expect(client.getQueryState(queryKeys.importBatches())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions', 'list', {}])?.isInvalidated).toBe(true)
  })

  it('também invalida listagem e ledger quando a reversão falha, para o histórico recarregar o estado atual', async () => {
    POST.mockRejectedValue(new Error('o lote já foi revertido'))
    const client = new QueryClient()
    seedDetailAndList(client, 9)
    client.setQueryData(['transactions', 'list', {}], { pages: [] })

    const { result } = renderHook(() => useRevertImport(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9).catch(() => undefined)
    })

    expect(client.getQueryState(queryKeys.importBatches())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions', 'list', {}])?.isInvalidated).toBe(true)
  })
})
