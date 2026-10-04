import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'

const { GET, POST } = vi.hoisted(() => ({ GET: vi.fn(), POST: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, GET, POST } }
})

const {
  useTransferSuggestions,
  useDetectTransfers,
  useAcceptSuggestion,
  useDismissSuggestion,
  useLinkTransfer,
  useUnlinkTransfer,
} = await import('./transfer-suggestions')

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  GET.mockReset()
  POST.mockReset()
})

describe('useTransferSuggestions', () => {
  it('busca a primeira página em GET /transfer-suggestions sem cursor', async () => {
    GET.mockResolvedValue({ data: { data: [], meta: { next_cursor: null } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    const { result } = renderHook(() => useTransferSuggestions(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(result.current.data).toBeDefined())
    })

    expect(GET).toHaveBeenCalledWith('/transfer-suggestions', { params: { query: { per_page: 20 } } })
  })
})

describe('useDetectTransfers', () => {
  it('chama o detect e invalida o razão (que já cobre transfer-suggestions)', async () => {
    POST.mockResolvedValue({ data: { data: { linked: 1, suggested: 2 } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useDetectTransfers(), { wrapper: wrapper(client) })
    let value: { linked: number; suggested: number } | undefined
    await act(async () => {
      value = await result.current.mutateAsync()
    })

    expect(POST).toHaveBeenCalledWith('/transfer-suggestions/detect')
    expect(value).toEqual({ linked: 1, suggested: 2 })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})

describe('useAcceptSuggestion', () => {
  it('ao ligar, invalida o razão (que já cobre transfer-suggestions)', async () => {
    POST.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useAcceptSuggestion(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9)
    })

    expect(POST).toHaveBeenCalledWith('/transfer-suggestions/{suggestion}/accept', { params: { path: { suggestion: 9 } } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })

  it('em erro (409: o backend já excluiu a sugestão desatualizada), ainda assim invalida as sugestões', async () => {
    POST.mockResolvedValue({
      data: undefined,
      error: { code: 'transfer_link_invalid', message: 'Essas transações não formam uma transferência.' },
      response: { ok: false, status: 409 },
    })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useAcceptSuggestion(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9).catch(() => undefined)
    })

    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    // Diferente do sucesso: erro não passa por invalidateLedger, então o razão não é tocado.
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(false)
  })
})

describe('useDismissSuggestion', () => {
  it('descarta e invalida só as sugestões', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useDismissSuggestion(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9)
    })

    expect(POST).toHaveBeenCalledWith('/transfer-suggestions/{suggestion}/dismiss', { params: { path: { suggestion: 9 } } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(false)
  })

  it('mesmo em erro (409: outra aba já resolveu a sugestão), invalida as sugestões (onSettled)', async () => {
    POST.mockResolvedValue({
      data: undefined,
      error: { code: 'transfer_suggestion_not_pending', message: 'Esta sugestão não está mais pendente.' },
      response: { ok: false, status: 409 },
    })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')

    const { result } = renderHook(() => useDismissSuggestion(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync(9).catch(() => undefined)
    })

    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
  })
})

describe('useLinkTransfer', () => {
  it('liga e invalida o razão (que já cobre transfer-suggestions)', async () => {
    POST.mockResolvedValue({ data: { data: {} }, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transferSuggestions(), 'x')
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useLinkTransfer(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync({ out_transaction_id: 1, in_transaction_id: 2 })
    })

    expect(POST).toHaveBeenCalledWith('/transfers/link', { body: { out_transaction_id: 1, in_transaction_id: 2 } })
    expect(client.getQueryState(queryKeys.transferSuggestions())?.isInvalidated).toBe(true)
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })
})

describe('useUnlinkTransfer', () => {
  it('remove a transferência do cache (a rota passa a devolver 404) e invalida o razão', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    const client = new QueryClient()
    client.setQueryData(queryKeys.transfer('uuid-1'), { transfer_id: 'uuid-1' })
    client.setQueryData(['transactions'], 'x')

    const { result } = renderHook(() => useUnlinkTransfer(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync('uuid-1')
    })

    expect(POST).toHaveBeenCalledWith('/transfers/{transfer}/unlink', { params: { path: { transfer: 'uuid-1' } } })
    // Sem cache: um remount da tela de edição buscaria de novo, e a API já responde 404
    // (TransferController::show() não acha mais as duas pernas com este transfer_id).
    expect(client.getQueryCache().find({ queryKey: queryKeys.transfer('uuid-1') })).toBeUndefined()
    expect(client.getQueryState(['transactions'])?.isInvalidated).toBe(true)
  })

  it('não espera as invalidações do razão: resolve mesmo com uma query ativa presa num refetch', async () => {
    POST.mockResolvedValue({ data: undefined, error: undefined, response: { ok: true } })
    GET.mockResolvedValue({ data: { data: [], meta: { next_cursor: null } }, error: undefined, response: { ok: true } })
    const client = new QueryClient()

    // Mantém `transfer-suggestions` ativa (montada) para a invalidação do razão disparar um
    // refetch de verdade, não só marcar como invalidada.
    const { result: suggestions } = renderHook(() => useTransferSuggestions(), { wrapper: wrapper(client) })
    await act(async () => {
      await vi.waitFor(() => expect(suggestions.current.data).toBeDefined())
    })

    // A partir daqui, qualquer novo GET nunca resolve — simula um refetch lento.
    GET.mockImplementation(() => new Promise(() => {}))

    // Se a implementação esperasse `invalidateLedger` (que dispara um refetch da query ativa
    // acima, nunca resolvido), este `await` ficaria pendente para sempre e o teste expiraria
    // pelo timeout padrão do Vitest — chegar até aqui já comprova que não está esperando.
    const { result } = renderHook(() => useUnlinkTransfer(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current.mutateAsync('uuid-2')
    })

    expect(POST).toHaveBeenCalledWith('/transfers/{transfer}/unlink', { params: { path: { transfer: 'uuid-2' } } })
  })
})
