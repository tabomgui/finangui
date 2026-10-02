import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type StoreAccountRequest = components['schemas']['StoreAccountRequest']
type UpdateAccountRequest = components['schemas']['UpdateAccountRequest']

export function useAccounts(includeArchived = false) {
  return useQuery({
    queryKey: queryKeys.accounts(includeArchived),
    queryFn: async () =>
      (await unwrap(api.GET('/accounts', { params: { query: { include_archived: includeArchived } } }))).data,
    placeholderData: keepPreviousData,
  })
}

export function useCreateAccount() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreAccountRequest) => (await unwrap(api.POST('/accounts', { body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useUpdateAccount() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateAccountRequest }) =>
      (await unwrap(api.PATCH('/accounts/{account}', { params: { path: { account: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useDeleteAccount() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/accounts/{account}', { params: { path: { account: id } } }))
    },
    onSuccess: () => invalidateLedger(queryClient),
  })
}
