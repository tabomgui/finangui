import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { today } from '@/lib/date'

const { GET } = vi.hoisted(() => ({ GET: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET } }
})

const { useRecentTransactions } = await import('./transactions')

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient()
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useRecentTransactions', () => {
  it('limita a hoje, para não listar parcelas projetadas futuras entre os "recentes"', async () => {
    GET.mockResolvedValue({ data: { data: [] }, error: undefined, response: { ok: true } })

    renderHook(() => useRecentTransactions(), { wrapper })

    await waitFor(() => expect(GET).toHaveBeenCalled())
    expect(GET).toHaveBeenCalledWith('/transactions', { params: { query: { per_page: 5, to: today() } } })
  })
})
