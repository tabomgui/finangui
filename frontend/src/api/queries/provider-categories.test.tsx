import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'

const { GET, PATCH } = vi.hoisted(() => ({ GET: vi.fn(), PATCH: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, PATCH } }
})

const { meKey, useUpdateProviderCategories } = await import('./auth')
const { useProviderCategorizedCount } = await import('./categorization')

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

const updatedUser = { id: 1, name: 'Pessoa Exemplo', use_provider_categories: false }

beforeEach(() => {
  GET.mockReset()
  PATCH.mockReset()
})

describe('useProviderCategorizedCount', () => {
  it('busca a contagem em GET /categorization/provider-categorized-count', async () => {
    GET.mockResolvedValue({ data: { data: { count: 4 } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useProviderCategorizedCount(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(result.current.data).toBeDefined())
    })

    expect(GET).toHaveBeenCalledWith('/categorization/provider-categorized-count')
    expect(result.current.data).toEqual({ count: 4 })
  })
})

describe('useUpdateProviderCategories', () => {
  it('desliga via PATCH /me, grava o usuário no cache e não invalida o razão sem limpeza', async () => {
    PATCH.mockResolvedValue({
      data: { data: updatedUser, meta: { cleared_provider_categories: 0 } },
      error: undefined,
      response: { ok: true },
    })
    const client = new QueryClient()
    client.setQueryData(meKey, { ...updatedUser, use_provider_categories: true })
    client.setQueryData(['transactions', 'recent'], [])

    const { result } = renderHook(() => useUpdateProviderCategories(), { wrapper: wrapper(client) })
    let response: Awaited<ReturnType<typeof result.current.mutateAsync>> | undefined
    await act(async () => {
      response = await result.current.mutateAsync({ use_provider_categories: false })
    })

    expect(PATCH).toHaveBeenCalledWith('/me', { body: { use_provider_categories: false } })
    expect(response).toEqual({ user: updatedUser, cleared: 0 })
    expect(client.getQueryData(meKey)).toEqual(updatedUser)
    expect(client.getQueryState(['transactions', 'recent'])?.isInvalidated).toBe(false)
  })

  it('com clear_provider_categories, devolve quantos foram limpos e invalida o razão e a contagem', async () => {
    PATCH.mockResolvedValue({
      data: { data: updatedUser, meta: { cleared_provider_categories: 5 } },
      error: undefined,
      response: { ok: true },
    })
    const client = new QueryClient()
    client.setQueryData(['transactions', 'recent'], [])
    client.setQueryData(queryKeys.providerCategorizedCount(), { count: 5 })

    const { result } = renderHook(() => useUpdateProviderCategories(), { wrapper: wrapper(client) })
    let response: Awaited<ReturnType<typeof result.current.mutateAsync>> | undefined
    await act(async () => {
      response = await result.current.mutateAsync({ use_provider_categories: false, clear_provider_categories: true })
    })

    expect(response).toEqual({ user: updatedUser, cleared: 5 })
    expect(client.getQueryState(['transactions', 'recent'])?.isInvalidated).toBe(true)
    expect(client.getQueryState(queryKeys.providerCategorizedCount())?.isInvalidated).toBe(true)
  })
})
