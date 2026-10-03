import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

const { POST } = vi.hoisted(() => ({ POST: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, POST } }
})

const { useCancelInstallmentPlan } = await import('./cards')

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient()
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useCancelInstallmentPlan', () => {
  it('cancela pelo POST /installment-plans/{plan}/cancel', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })

    const { result } = renderHook(() => useCancelInstallmentPlan(), { wrapper })
    await act(async () => {
      await result.current.mutateAsync(7)
    })

    await waitFor(() =>
      expect(POST).toHaveBeenCalledWith('/installment-plans/{plan}/cancel', { params: { path: { plan: 7 } } }),
    )
  })
})
