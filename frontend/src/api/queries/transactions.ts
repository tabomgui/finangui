import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type StoreTransactionRequest = components['schemas']['StoreTransactionRequest']
type UpdateTransactionRequest = components['schemas']['UpdateTransactionRequest']

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
    onSuccess: () => invalidateLedger(queryClient),
  })
}
