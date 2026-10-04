import { useInfiniteQuery, useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query'
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
    queryFn: async ({ pageParam }) =>
      unwrap(
        api.GET('/transfer-suggestions', {
          params: { query: { per_page: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) } },
        }),
      ),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  })
}

function invalidateSuggestions(queryClient: QueryClient) {
  return queryClient.invalidateQueries({ queryKey: queryKeys.transferSuggestions() })
}

/** `invalidateLedger` já inclui `transfer-suggestions` (ver query-keys.ts): basta chamar ela. */
export function useDetectTransfers() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => (await unwrap(api.POST('/transfer-suggestions/detect'))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useAcceptSuggestion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      (await unwrap(api.POST('/transfer-suggestions/{suggestion}/accept', { params: { path: { suggestion: id } } }))).data,
    onSuccess: () => invalidateLedger(queryClient),
    // Só em erro: o 409 (transfer_link_invalid) já é o backend excluindo a sugestão
    // desatualizada antes de recusar (ver AcceptTransferSuggestion::handle()) — a lista precisa
    // refletir isso, mas em sucesso invalidateLedger acima já cobre `transfer-suggestions`.
    onError: () => invalidateSuggestions(queryClient),
  })
}

export function useDismissSuggestion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.POST('/transfer-suggestions/{suggestion}/dismiss', { params: { path: { suggestion: id } } }))
    },
    // onSettled, não onSuccess: um 409 (transfer_suggestion_not_pending, ex.: outra aba já
    // aceitou) também significa que a lista local está desatualizada.
    onSettled: () => invalidateSuggestions(queryClient),
  })
}

/** "Juntar como transferência" na seleção em massa: janela de 7 dias no backend (decisão humana). */
export function useLinkTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: LinkTransferRequest) => (await unwrap(api.POST('/transfers/link', { body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useUnlinkTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (transferId: string) => {
      await expectOk(api.POST('/transfers/{transfer}/unlink', { params: { path: { transfer: transferId } } }))
    },
    onSuccess: (_data, transferId) => {
      // Some imediatamente do cache (a rota agora devolve 404 para este transfer_id): remover
      // em vez de só invalidar evita servir o dado velho se algo remontar a tela antes do
      // refetch. Dispara as invalidações do razão sem esperar (sem `return`/`await`) — quem
      // chama (ex.: a edição, que navega de volta ao confirmar) não deve ficar bloqueado pelo
      // refetch delas.
      queryClient.removeQueries({ queryKey: queryKeys.transfer(transferId) })
      void invalidateLedger(queryClient)
    },
  })
}
