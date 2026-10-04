import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

const { GET, POST, PATCH, DELETE } = vi.hoisted(() => ({
  GET: vi.fn(),
  POST: vi.fn(),
  PATCH: vi.fn(),
  DELETE: vi.fn(),
}))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, POST, PATCH, DELETE } }
})

const {
  useRecurrences,
  useRecurrence,
  useCreateRecurrence,
  useUpdateRecurrence,
  useDeleteRecurrence,
  useOverdueOccurrences,
  useConfirmOccurrence,
  useSkipOccurrence,
} = await import('./recurrences')

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient()
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useRecurrences', () => {
  it('busca a lista em /recurrences', async () => {
    GET.mockResolvedValue({ data: { data: [] }, error: undefined, response: { ok: true } })

    renderHook(() => useRecurrences(), { wrapper })

    await waitFor(() => expect(GET).toHaveBeenCalledWith('/recurrences'))
  })
})

describe('useRecurrence', () => {
  it('busca o detalhe em /recurrences/{recurrence}', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })

    renderHook(() => useRecurrence(7), { wrapper })

    await waitFor(() => expect(GET).toHaveBeenCalledWith('/recurrences/{recurrence}', { params: { path: { recurrence: 7 } } }))
  })
})

describe('useCreateRecurrence', () => {
  it('envia POST /recurrences com o corpo', async () => {
    POST.mockResolvedValue({ data: { data: { id: 1 } }, error: undefined, response: { ok: true } })
    const { result } = renderHook(() => useCreateRecurrence(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync({ transaction_id: 5, frequency: 'monthly' })
    })

    expect(POST).toHaveBeenCalledWith('/recurrences', { body: { transaction_id: 5, frequency: 'monthly' } })
  })
})

describe('useUpdateRecurrence', () => {
  it('envia PATCH /recurrences/{recurrence} com o corpo', async () => {
    PATCH.mockResolvedValue({ data: { data: { id: 1 } }, error: undefined, response: { ok: true } })
    const { result } = renderHook(() => useUpdateRecurrence(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync({ id: 1, body: { description: 'Aluguel' } })
    })

    expect(PATCH).toHaveBeenCalledWith('/recurrences/{recurrence}', {
      params: { path: { recurrence: 1 } },
      body: { description: 'Aluguel' },
    })
  })
})

describe('useDeleteRecurrence', () => {
  it('envia DELETE /recurrences/{recurrence}', async () => {
    DELETE.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const { result } = renderHook(() => useDeleteRecurrence(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync(1)
    })

    expect(DELETE).toHaveBeenCalledWith('/recurrences/{recurrence}', { params: { path: { recurrence: 1 } } })
  })
})

describe('useOverdueOccurrences', () => {
  it('busca a lista em /recurrences/overdue', async () => {
    GET.mockResolvedValue({ data: { data: [] }, error: undefined, response: { ok: true } })

    renderHook(() => useOverdueOccurrences(), { wrapper })

    await waitFor(() => expect(GET).toHaveBeenCalledWith('/recurrences/overdue'))
  })
})

describe('useConfirmOccurrence', () => {
  it('envia POST /recurrences/occurrences/{transaction}/confirm com o corpo', async () => {
    POST.mockResolvedValue({ data: { data: { id: 1 } }, error: undefined, response: { ok: true } })
    const { result } = renderHook(() => useConfirmOccurrence(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync({ id: 9, body: { amount: 1000 } })
    })

    expect(POST).toHaveBeenCalledWith('/recurrences/occurrences/{transaction}/confirm', {
      params: { path: { transaction: 9 } },
      body: { amount: 1000 },
    })
  })
})

describe('useSkipOccurrence', () => {
  it('envia POST /recurrences/occurrences/{transaction}/skip', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const { result } = renderHook(() => useSkipOccurrence(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync(9)
    })

    expect(POST).toHaveBeenCalledWith('/recurrences/occurrences/{transaction}/skip', { params: { path: { transaction: 9 } } })
  })
})
