import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateCategories, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type StoreCategoryRequest = components['schemas']['StoreCategoryRequest']
type UpdateCategoryRequest = components['schemas']['UpdateCategoryRequest']

export function useCategories(includeArchived = false) {
  return useQuery({
    queryKey: queryKeys.categories(includeArchived),
    queryFn: async () =>
      (await unwrap(api.GET('/categories', { params: { query: { include_archived: includeArchived } } }))).data,
    placeholderData: keepPreviousData,
  })
}

export function useCreateCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreCategoryRequest) => (await unwrap(api.POST('/categories', { body }))).data,
    onSuccess: () => invalidateCategories(queryClient),
  })
}

export function useUpdateCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateCategoryRequest }) =>
      (await unwrap(api.PATCH('/categories/{category}', { params: { path: { category: id } }, body }))).data,
    onSuccess: () => invalidateCategories(queryClient),
  })
}

export function useDeleteCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/categories/{category}', { params: { path: { category: id } } }))
    },
    onSuccess: () => invalidateCategories(queryClient),
  })
}
