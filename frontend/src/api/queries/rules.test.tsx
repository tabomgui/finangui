import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Rule } from '@/api/types'

const { PUT, POST } = vi.hoisted(() => ({ PUT: vi.fn(), POST: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, PUT, POST } }
})

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { useReorderRules, useCreateRule, useApplyRuleReconcile, useApplyRuleReview } = await import('./rules')

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

describe('useCreateRule', () => {
  it('semeia o cache do detalhe com a regra recém-criada', async () => {
    const created = rule(42, 'Uber')
    POST.mockResolvedValue({ data: { data: created }, error: undefined, response: { ok: true, status: 201 } })
    const client = new QueryClient()
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>

    const { result } = renderHook(() => useCreateRule(), { wrapper })

    act(() => {
      result.current.mutate({ match: 'all', conditions: [], actions: [], name: 'Uber' })
    })

    await waitFor(() => expect(client.getQueryData(queryKeys.rule(42))).toEqual(created))
  })
})

describe('useApplyRuleReconcile', () => {
  it('manda os grupos para a regra certa e invalida o razão e as regras', async () => {
    POST.mockResolvedValue({ data: { data: { recategorize: 1, fill: 2, clear: 0, skipped: 0 } }, error: undefined, response: { ok: true, status: 200 } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transactions({}), 'x')
    client.setQueryData(queryKeys.rules(), [])
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>

    const { result } = renderHook(() => useApplyRuleReconcile(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync({ id: 7, body: { groups: ['recategorize', 'fill'] } })
    })

    expect(POST).toHaveBeenCalledWith('/rules/{rule}/reconcile', { params: { path: { rule: 7 } }, body: { groups: ['recategorize', 'fill'] } })
    await waitFor(() => {
      expect(client.getQueryState(queryKeys.transactions({}))?.isInvalidated).toBe(true)
      expect(client.getQueryState(queryKeys.rules())?.isInvalidated).toBe(true)
    })
  })
})

describe('useApplyRuleReview', () => {
  it('manda as linhas e invalida o razão e as regras, inclusive a própria revisão', async () => {
    POST.mockResolvedValue({ data: { data: { applied: 1, skipped: 0 } }, error: undefined, response: { ok: true, status: 200 } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transactions({}), 'x')
    client.setQueryData(queryKeys.ruleReview(), 'x')
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>

    const { result } = renderHook(() => useApplyRuleReview(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync({ items: [{ transaction_id: 3, category_id: 8 }, { transaction_id: 4 }] })
    })

    expect(POST).toHaveBeenCalledWith('/rules/review/apply', { body: { items: [{ transaction_id: 3, category_id: 8 }, { transaction_id: 4 }] } })
    await waitFor(() => {
      expect(client.getQueryState(queryKeys.transactions({}))?.isInvalidated).toBe(true)
      expect(client.getQueryState(queryKeys.ruleReview())?.isInvalidated).toBe(true)
    })
  })
})
