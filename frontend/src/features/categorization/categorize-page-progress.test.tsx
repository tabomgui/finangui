import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import { CategorizePage } from './categorize-page'

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET: vi.fn() } }
})

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
  useNotifications: () => ({ data: undefined, fetchNextPage: vi.fn(), hasNextPage: false, isFetchingNextPage: false, isPending: true }),
  useMarkRead: () => ({ mutate: vi.fn() }),
  useMarkAllRead: () => ({ mutate: vi.fn(), isPending: false }),
}))

const { api } = await import('@/api/client')

function ok(body: unknown) {
  return Promise.resolve({ data: { data: body }, error: undefined, response: { ok: true, status: 200 } as Response })
}

describe('CategorizePage: progresso', () => {
  it('tira o baseline de uma contagem buscada na entrada, mesmo com a do cache ainda fresca', async () => {
    vi.mocked(api.GET).mockImplementation(((path: string) => {
      if (path === '/categorization/pending-count') return ok({ total_pending: 12, group_count: 4 })
      if (path === '/categorization/pending') return ok({ total_pending: 12, group_count: 0, groups: [] })
      return ok([])
    }) as never)

    // Mesmo `staleTime` do app: a contagem que a Início deixou no cache ainda está fresca.
    const client = new QueryClient({ defaultOptions: { queries: { staleTime: 30_000, retry: false } } })
    client.setQueryData(queryKeys.pendingCategorizationCount(), { total_pending: 10, group_count: 3 })

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/categorizar']}>
          <CategorizePage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    // Com o valor do cache a barra ainda não aparece; ela surge com o valor fresco como baseline.
    expect(screen.queryByText(/ de 10/)).not.toBeInTheDocument()
    expect(await screen.findByText('0 de 12 (0%)')).toBeInTheDocument()
    expect(api.GET).toHaveBeenCalledWith('/categorization/pending-count')
  })
})
