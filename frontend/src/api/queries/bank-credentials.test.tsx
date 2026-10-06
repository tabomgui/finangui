import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { meKey } from '@/api/queries/auth'
import { queryKeys } from '@/api/query-keys'

const { GET, PUT, DELETE } = vi.hoisted(() => ({ GET: vi.fn(), PUT: vi.fn(), DELETE: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, PUT, DELETE } }
})

const { useBankCredentials, useSaveBankCredentials, useDeleteBankCredentials } = await import('./bank-credentials')

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  GET.mockReset()
  PUT.mockReset()
  DELETE.mockReset()
})

describe('useBankCredentials', () => {
  it('busca o estado em GET /bank-credentials', async () => {
    GET.mockResolvedValue({ data: { data: { configured: false, provider: 'pluggy' } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useBankCredentials(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(result.current.data).toBeDefined())
    })

    expect(GET).toHaveBeenCalledWith('/bank-credentials')
    expect(result.current.data).toEqual({ configured: false, provider: 'pluggy' })
  })
})

describe('useSaveBankCredentials', () => {
  it('salva via PUT e invalida credenciais, me e conexões', async () => {
    PUT.mockResolvedValue({
      data: { data: { configured: true, provider: 'pluggy', client_id_hint: '3be8', verified_at: '2026-10-06T12:00:00Z' } },
      error: undefined,
      response: { ok: true },
    })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankCredentials(), { configured: false, provider: 'pluggy' })
    client.setQueryData(meKey, { banking_enabled: false })
    client.setQueryData(queryKeys.bankConnections(), [])

    const { result } = renderHook(() => useSaveBankCredentials(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ client_id: '11111111-1111-1111-1111-111111111111', client_secret: 'segredo' })
    })

    expect(PUT).toHaveBeenCalledWith('/bank-credentials', {
      body: { client_id: '11111111-1111-1111-1111-111111111111', client_secret: 'segredo' },
    })
    expect(client.getQueryState(queryKeys.bankCredentials())?.isInvalidated).toBe(true)
    expect(client.getQueryState(meKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(queryKeys.bankConnections())?.isInvalidated).toBe(true)
  })
})

describe('useDeleteBankCredentials', () => {
  it('remove via DELETE e invalida credenciais, me e conexões', async () => {
    DELETE.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.bankCredentials(), { configured: true, provider: 'pluggy' })
    client.setQueryData(meKey, { banking_enabled: true })
    client.setQueryData(queryKeys.bankConnections(), [])

    const { result } = renderHook(() => useDeleteBankCredentials(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync()
    })

    expect(DELETE).toHaveBeenCalledWith('/bank-credentials')
    expect(client.getQueryState(queryKeys.bankCredentials())?.isInvalidated).toBe(true)
    expect(client.getQueryState(meKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(queryKeys.bankConnections())?.isInvalidated).toBe(true)
  })
})
