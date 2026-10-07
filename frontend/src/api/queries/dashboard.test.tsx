import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

const { GET } = vi.hoisted(() => ({ GET: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET } }
})

const { useDashboard } = await import('./dashboard')

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient()
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useDashboard', () => {
  it('sem dia, manda só o mês', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })

    renderHook(() => useDashboard('2026-10'), { wrapper })

    await waitFor(() => expect(GET).toHaveBeenCalled())
    expect(GET).toHaveBeenCalledWith('/dashboard', { params: { query: { month: '2026-10' } } })
  })

  it('com dia, manda month e date', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })

    renderHook(() => useDashboard('2026-10', '2026-10-04'), { wrapper })

    await waitFor(() => expect(GET).toHaveBeenCalled())
    expect(GET).toHaveBeenCalledWith('/dashboard', { params: { query: { month: '2026-10', date: '2026-10-04' } } })
  })
})
