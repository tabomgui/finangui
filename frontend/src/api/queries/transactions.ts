import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { compactFilters, invalidateLedger, queryKeys, type TransactionFilters } from '@/api/query-keys'
import type { components } from '@/api/schema'

type StoreTransactionRequest = components['schemas']['StoreTransactionRequest']
type UpdateTransactionRequest = components['schemas']['UpdateTransactionRequest']

const PAGE_SIZE = 30

export function useTransactions(filters: TransactionFilters) {
  return useInfiniteQuery({
    queryKey: queryKeys.transactions(filters),
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) =>
      unwrap(
        api.GET('/transactions', {
          params: { query: { ...compactFilters(filters), per_page: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) } },
        }),
      ),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
    placeholderData: keepPreviousData,
  })
}

export function useRecentTransactions(limit = 5) {
  return useQuery({
    queryKey: queryKeys.recentTransactions(),
    queryFn: async () => (await unwrap(api.GET('/transactions', { params: { query: { per_page: limit } } }))).data,
  })
}

export function useTransaction(id: number | null) {
  return useQuery({
    queryKey: queryKeys.transaction(id ?? 0),
    enabled: id !== null,
    queryFn: async () =>
      (await unwrap(api.GET('/transactions/{transaction}', { params: { path: { transaction: id ?? 0 } } }))).data,
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
