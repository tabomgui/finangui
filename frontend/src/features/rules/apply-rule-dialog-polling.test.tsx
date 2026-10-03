import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Rule } from '@/api/types'
import { ApplyRuleDialog } from './apply-rule-dialog'

/**
 * Este arquivo usa os hooks *reais* de `@/api/queries/rules` (sem mock) — só a API é mockada —
 * porque o bug que ele prova (C1) é sobre o comportamento de cache do TanStack Query
 * (structural sharing: refetch com corpo igual mantém a mesma referência de `data`, então um
 * `useEffect` que depende de `data` nunca roda de novo). Um mock manual de `useRule` não
 * reproduziria isso: teria que fingir o bug para "testá-lo".
 */
const { GET, POST } = vi.hoisted(() => ({ GET: vi.fn(), POST: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, POST } }
})

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}))

function rule(overrides: Partial<Rule> = {}): Rule {
  return {
    id: 5,
    name: 'Uber',
    priority: 1,
    is_active: true,
    match: 'all',
    conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
    actions: [{ type: 'set_category', category_id: 1 }],
    last_applied_at: null,
    last_applied_changes: null,
    ...overrides,
  }
}

// O envelope real da API é `{ data: T }` (ver `RuleResource`/`RulePreviewResource` no schema);
// `unwrap` já descasca esse envelope, e os hooks de `api/queries/rules.ts` pegam `.data` de novo
// por cima do que `unwrap` devolve — por isso o duplo `data` aqui.
function ok<T>(data: T) {
  return { data: { data }, error: undefined, response: { ok: true, status: 200 } as Response }
}

beforeEach(() => {
  vi.useFakeTimers()
  GET.mockReset()
  POST.mockReset()
  vi.mocked(toast).mockClear()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('ApplyRuleDialog — polling com GET real (C1)', () => {
  it('estoura o tempo quando o GET devolve sempre a mesma regra (sem mudar last_applied_at), e o botão volta ao normal', async () => {
    const unchanged = rule()
    GET.mockImplementation(async () => ok(unchanged))
    POST.mockImplementation(async (path: string) => (path === '/rules/preview' ? ok({ matched: 0, changed: 0, sample: [] }) : ok({ queued: true })))

    const client = new QueryClient()
    render(
      <QueryClientProvider client={client}>
        <ApplyRuleDialog rule={unchanged} overwrite={false} onOverwriteChange={() => {}} disabled={false} />
      </QueryClientProvider>,
    )

    // `waitFor` não combina bem com fake timers (o polling interno dele também usa `setTimeout`,
    // que fica congelado) — por isso cada passo flush as promises pendentes à mão, avançando os
    // timers em 0ms, em vez de esperar por elas.
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(POST).toHaveBeenCalledWith('/rules/{rule}/apply', expect.anything())
    expect(screen.getByText('Aplicando…')).toBeInTheDocument()

    // 60s de polling a cada 2s, sempre com o mesmo corpo: sem um timer real e independente do
    // resultado do fetch, `applying` nunca voltaria a `false` (ver comentário no componente).
    await act(async () => {
      await vi.advanceTimersByTimeAsync(61_000)
    })

    expect(toast).toHaveBeenCalledWith('A aplicação continua em segundo plano.')
    const button = screen.getByRole('button', { name: 'Aplicar às existentes' })
    expect(button).toBeInTheDocument()
    expect(button).not.toBeDisabled()
  })
})
