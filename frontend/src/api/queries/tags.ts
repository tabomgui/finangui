import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateTags, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type SaveTagRequest = components['schemas']['SaveTagRequest']

export function useTags() {
  return useQuery({
    queryKey: queryKeys.tags(),
    queryFn: async () => (await unwrap(api.GET('/tags'))).data,
    staleTime: 5 * 60_000,
  })
}

export function useCreateTag() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: SaveTagRequest) => (await unwrap(api.POST('/tags', { body }))).data,
    onSuccess: () => invalidateTags(queryClient),
  })
}

export function useUpdateTag() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: SaveTagRequest }) =>
      (await unwrap(api.PATCH('/tags/{tag}', { params: { path: { tag: id } }, body }))).data,
    onSuccess: () => invalidateTags(queryClient),
  })
}

export function useDeleteTag() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/tags/{tag}', { params: { path: { tag: id } } }))
    },
    onSuccess: () => invalidateTags(queryClient),
  })
}
