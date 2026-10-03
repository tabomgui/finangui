import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Rule } from '@/api/types'

const { PUT } = vi.hoisted(() => ({ PUT: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, PUT } }
})

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { useReorderRules } = await import('./rules')

function rule(id: number, name: string): Rule {
  return {
    id,
    name,
    priority: id,
    is_active: true,
    match: 'all',
    conditions: [{ field: 'description', op: 'contains', value: 'x' }],
    actions: [{ type: 'ignore' }],
    last_applied_at: null,
    last_applied_changes: null,
  }
}

function clientWithRules(rules: Rule[]) {
  const client = new QueryClient()
  client.setQueryData(queryKeys.rules(), rules)
  return client
}

function orderedIds(client: QueryClient) {
  return client.getQueryData<Rule[]>(queryKeys.rules())?.map((item) => item.id)
}

type PutResult = { data: undefined; error: unknown; response: Response }

/** Promessa controlada à mão: segura o PUT em voo para inspecionar o estado otimista antes de resolver. */
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

describe('useReorderRules', () => {
  it('grava a nova ordem no cache antes do PUT confirmar e chama a rota certa', async () => {
    PUT.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true, status: 204 } })
    const client = clientWithRules([rule(1, 'A'), rule(2, 'B'), rule(3, 'C')])
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>

    const { result } = renderHook(() => useReorderRules(), { wrapper })

    act(() => {
      result.current.mutate([2, 1, 3])
    })

    await waitFor(() => expect(orderedIds(client)).toEqual([2, 1, 3]))
    await waitFor(() => expect(PUT).toHaveBeenCalledWith('/rules/order', { body: { ids: [2, 1, 3] } }))
  })

  it('desfaz a ordem otimista e avisa quando o PUT falha', async () => {
    const put = deferred<PutResult>()
    PUT.mockReturnValue(put.promise)
    const client = clientWithRules([rule(1, 'A'), rule(2, 'B'), rule(3, 'C')])
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>

    const { result } = renderHook(() => useReorderRules(), { wrapper })

    act(() => {
      result.current.mutate([2, 1, 3])
    })

    // Otimista, com o PUT ainda em voo...
    await waitFor(() => expect(orderedIds(client)).toEqual([2, 1, 3]))

    await act(async () => {
      put.resolve({
        data: undefined,
        error: { message: 'Envie todas as regras, sem repetir.' },
        response: { ok: false, status: 422 } as Response,
      })
      await put.promise
    })

    // ...depois desfeito, com aviso.
    await waitFor(() => expect(orderedIds(client)).toEqual([1, 2, 3]))
    expect(toast.error).toHaveBeenCalledWith('Envie todas as regras, sem repetir.')
  })
})
