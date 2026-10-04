import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type StoreRecurrenceRequest = components['schemas']['StoreRecurrenceRequest']
type UpdateRecurrenceRequest = components['schemas']['UpdateRecurrenceRequest']
type ConfirmOccurrenceRequest = components['schemas']['ConfirmOccurrenceRequest']

export function useRecurrences() {
  return useQuery({
    queryKey: queryKeys.recurrences(),
    queryFn: async () => (await unwrap(api.GET('/recurrences'))).data,
  })
}

export function useRecurrence(id: number) {
  return useQuery({
    queryKey: queryKeys.recurrence(id),
    queryFn: async () =>
      (await unwrap(api.GET('/recurrences/{recurrence}', { params: { path: { recurrence: id } } }))).data,
  })
}

export function useCreateRecurrence() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreRecurrenceRequest) => (await unwrap(api.POST('/recurrences', { body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useUpdateRecurrence() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateRecurrenceRequest }) =>
      (await unwrap(api.PATCH('/recurrences/{recurrence}', { params: { path: { recurrence: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useDeleteRecurrence() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/recurrences/{recurrence}', { params: { path: { recurrence: id } } }))
    },
    onSuccess: () => invalidateLedger(queryClient),
  })
}

/** Previstas atrasadas ("não aconteceu?"): ver `GET /recurrences/overdue`. */
export function useOverdueOccurrences() {
  return useQuery({
    queryKey: queryKeys.overdueOccurrences(),
    queryFn: async () => (await unwrap(api.GET('/recurrences/overdue'))).data,
  })
}

export function useConfirmOccurrence() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: ConfirmOccurrenceRequest }) =>
      (
        await unwrap(
          api.POST('/recurrences/occurrences/{transaction}/confirm', { params: { path: { transaction: id } }, body }),
        )
      ).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useSkipOccurrence() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.POST('/recurrences/occurrences/{transaction}/skip', { params: { path: { transaction: id } } }))
    },
    onSuccess: () => invalidateLedger(queryClient),
  })
}
