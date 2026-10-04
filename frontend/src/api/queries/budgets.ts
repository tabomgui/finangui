import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateBudgets, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type SaveBudgetRequest = components['schemas']['SaveBudgetRequest']

export function useMonthBudget(month: string) {
  return useQuery({
    queryKey: queryKeys.budgets(month),
    queryFn: async () => (await unwrap(api.GET('/budgets', { params: { query: { month } } }))).data,
    placeholderData: keepPreviousData,
  })
}

export function useSaveBudget() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: SaveBudgetRequest) => (await unwrap(api.PUT('/budgets', { body }))).data,
    onSuccess: () => invalidateBudgets(queryClient),
  })
}

export function useDeleteBudget() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (query: { category_id: number; month?: string }) => {
      await expectOk(api.DELETE('/budgets', { params: { query } }))
    },
    onSuccess: () => invalidateBudgets(queryClient),
  })
}
