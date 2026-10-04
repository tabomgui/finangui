import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'

const { POST } = vi.hoisted(() => ({ POST: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, POST } }
})

const { useUploadStatement } = await import('./imports')

// Resposta real do backend: o controller devolve o resource dentro de `{data: ...}`; o campo
// `data` que o `openapi-fetch` expõe é o corpo inteiro, não o resource já desembrulhado.
function previewResponse(batchId: number) {
  return {
    data: { data: { batch: { id: batchId }, rows: [], summary: {} } },
    error: undefined,
    response: { ok: true },
  }
}

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

describe('useUploadStatement', () => {
  it('envia um FormData com account_id e file, sem format quando não escolhido', async () => {
    POST.mockResolvedValue(previewResponse(9))

    const file = new File(['data'], 'extrato.csv', { type: 'text/csv' })
    const client = new QueryClient()
    const { result } = renderHook(() => useUploadStatement(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ account_id: 3, file })
    })

    expect(POST).toHaveBeenCalledTimes(1)
    const [path, options] = POST.mock.calls[0]
    expect(path).toBe('/import-batches')
    const body = options.body as FormData
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('account_id')).toBe('3')
    expect(body.get('file')).toBe(file)
    expect(body.has('format')).toBe(false)
  })

  it('inclui format quando informado', async () => {
    POST.mockResolvedValue(previewResponse(9))

    const file = new File(['data'], 'extrato.ofx')
    const client = new QueryClient()
    const { result } = renderHook(() => useUploadStatement(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ account_id: 1, file, format: 'ofx' })
    })

    await waitFor(() => expect((POST.mock.calls[0][1].body as FormData).get('format')).toBe('ofx'))
  })

  it('popula o cache do detalhe com a prévia recebida, para a tela de prévia não recalculá-la de novo', async () => {
    POST.mockResolvedValue(previewResponse(42))

    const file = new File(['data'], 'extrato.csv', { type: 'text/csv' })
    const client = new QueryClient()
    const { result } = renderHook(() => useUploadStatement(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ account_id: 3, file })
    })

    expect(client.getQueryData(queryKeys.importBatch(42))).toEqual({ batch: { id: 42 }, rows: [], summary: {} })
  })
})
