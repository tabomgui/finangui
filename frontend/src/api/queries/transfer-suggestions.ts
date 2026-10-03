import { keepPreviousData, useInfiniteQuery, useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type LinkTransferRequest = components['schemas']['LinkTransferRequest']

const PAGE_SIZE = 20

/** Paginada por cursor, como `useTransactions`: pode crescer bastante numa conta movimentada. */
export function useTransferSuggestions() {
  return useInfiniteQuery({
    queryKey: queryKeys.transferSuggestions(),
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) => {
      // Variável separada (não um literal direto na chamada) porque `cursor` não está tipado
      // nos parâmetros de query deste endpoint (TransferSuggestionController::index() lê a
      // página pelo CursorPaginator padrão do Laravel, sem FormRequest); isso evita o erro de
      // "excess property" do TypeScript sem precisar editar o schema gerado.
      const query = { per_page: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) }
      return unwrap(api.GET('/transfer-suggestions', { params: { query } }))
    },
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
    placeholderData: keepPreviousData,
  })
}

function invalidateSuggestions(queryClient: QueryClient) {
  return queryClient.invalidateQueries({ queryKey: queryKeys.transferSuggestions() })
}

export function useDetectTransfers() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => (await unwrap(api.POST('/transfer-suggestions/detect'))).data,
    onSuccess: () => Promise.all([invalidateSuggestions(queryClient), invalidateLedger(queryClient)]),
  })
}

export function useAcceptSuggestion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      (await unwrap(api.POST('/transfer-suggestions/{suggestion}/accept', { params: { path: { suggestion: id } } }))).data,
    onSuccess: () => invalidateLedger(queryClient),
    // Mesmo em erro (409 transfer_link_invalid): o backend já excluiu a sugestão desatualizada
    // antes de recusar (ver AcceptTransferSuggestion::handle()) — a lista precisa refletir isso.
    onSettled: () => invalidateSuggestions(queryClient),
  })
}

export function useDismissSuggestion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.POST('/transfer-suggestions/{suggestion}/dismiss', { params: { path: { suggestion: id } } }))
    },
    onSuccess: () => invalidateSuggestions(queryClient),
  })
}

/** "Juntar como transferência" na seleção em massa: janela de 7 dias no backend (decisão humana). */
export function useLinkTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: LinkTransferRequest) => (await unwrap(api.POST('/transfers/link', { body }))).data,
    onSuccess: () => Promise.all([invalidateSuggestions(queryClient), invalidateLedger(queryClient)]),
  })
}

export function useUnlinkTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (transferId: string) => {
      await expectOk(api.POST('/transfers/{transfer}/unlink', { params: { path: { transfer: transferId } } }))
    },
    onSuccess: () => Promise.all([invalidateSuggestions(queryClient), invalidateLedger(queryClient)]),
  })
}
