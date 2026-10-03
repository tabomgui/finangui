import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { compactFilters, invalidateLedger, queryKeys, type TransactionFilters } from '@/api/query-keys'
import type { components } from '@/api/schema'
import type { Transaction } from '@/api/types'
import { runInBatches } from '@/lib/batches'
import { today } from '@/lib/date'

type StoreTransactionRequest = components['schemas']['StoreTransactionRequest']
type UpdateTransactionRequest = components['schemas']['UpdateTransactionRequest']

const PAGE_SIZE = 30

/**
 * O gerador de tipos instancia `TransactionResource` de um jeito ligeiramente diferente em cada
 * endpoint (lista paginada vs. recurso único); numa união com tantos ramos quanto `categorization`,
 * o TypeScript por vezes trata essas instâncias como tipos "sem relação" mesmo sendo estruturalmente
 * iguais. Normaliza para o alias canônico já na saída da API, antes de qualquer outro código tocar o valor.
 */
function asTransaction(data: unknown): Transaction {
  return data as Transaction
}

export function useTransactions(filters: TransactionFilters) {
  return useInfiniteQuery({
    queryKey: queryKeys.transactions(filters),
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) => {
      const page = await unwrap(
        api.GET('/transactions', {
          params: { query: { ...compactFilters(filters), per_page: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) } },
        }),
      )
      return { ...page, data: page.data.map(asTransaction) }
    },
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
    placeholderData: keepPreviousData,
  })
}

/** "Recentes" não devem incluir parcelas projetadas futuras: limita a hoje. */
export function useRecentTransactions(limit = 5) {
  return useQuery({
    queryKey: queryKeys.recentTransactions(),
    queryFn: async () => {
      const { data } = await unwrap(api.GET('/transactions', { params: { query: { per_page: limit, to: today() } } }))
      return data.map(asTransaction)
    },
  })
}

export function useTransaction(id: number | null) {
  return useQuery({
    queryKey: queryKeys.transaction(id ?? 0),
    enabled: id !== null,
    queryFn: async () => {
      const { data } = await unwrap(api.GET('/transactions/{transaction}', { params: { path: { transaction: id ?? 0 } } }))
      return asTransaction(data)
    },
  })
}

export function useCreateTransaction() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreTransactionRequest) => (await unwrap(api.POST('/transactions', { body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useUpdateTransaction() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateTransactionRequest }) =>
      (await unwrap(api.PATCH('/transactions/{transaction}', { params: { path: { transaction: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

/**
 * Atualiza várias transações (no máximo 4 requisições em paralelo, sem parar na primeira falha)
 * e invalida o razão uma vez no fim.
 */
export function useBulkUpdateTransactions() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ ids, body }: { ids: number[]; body: (id: number) => UpdateTransactionRequest }) =>
      runInBatches(ids, (id) =>
        unwrap(api.PATCH('/transactions/{transaction}', { params: { path: { transaction: id } }, body: body(id) })),
      ),
    onSettled: () => invalidateLedger(queryClient),
  })
}

export function useDeleteTransaction() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/transactions/{transaction}', { params: { path: { transaction: id } } }))
    },
    onSuccess: (_data, id) => {
      // A transação (e, se era perna de uma transferência, a transferência) deixou de existir:
      // tira do cache em vez de só invalidar, para não reaparecer com dado velho antes do refetch.
      queryClient.removeQueries({ queryKey: queryKeys.transaction(id) })
      queryClient.removeQueries({ queryKey: queryKeys.transfersRoot() })
      return invalidateLedger(queryClient)
    },
  })
}
