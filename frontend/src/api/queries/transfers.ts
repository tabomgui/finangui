import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'
import type { Transfer } from '@/api/types'

type StoreTransferRequest = components['schemas']['StoreTransferRequest']
type UpdateTransferRequest = components['schemas']['UpdateTransferRequest']

export function useTransfer(id: string | null) {
  return useQuery({
    queryKey: queryKeys.transfer(id ?? ''),
    enabled: id !== null,
    queryFn: async () => {
      const { data } = await unwrap(api.GET('/transfers/{transfer}', { params: { path: { transfer: id ?? '' } } }))
      // Mesmo caso de `asTransaction` em `api/queries/transactions.ts`: normaliza para o alias canônico.
      return data as unknown as Transfer
    },
  })
}

export function useCreateTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreTransferRequest) => (await unwrap(api.POST('/transfers', { body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useUpdateTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: string; body: UpdateTransferRequest }) =>
      (await unwrap(api.PATCH('/transfers/{transfer}', { params: { path: { transfer: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}
