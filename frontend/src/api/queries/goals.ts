import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateGoals, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type StoreGoalRequest = components['schemas']['StoreGoalRequest']
type UpdateGoalRequest = components['schemas']['UpdateGoalRequest']
type StoreGoalContributionRequest = components['schemas']['StoreGoalContributionRequest']

export function useGoals() {
  return useQuery({
    queryKey: queryKeys.goals(),
    queryFn: async () => (await unwrap(api.GET('/goals'))).data,
  })
}

export function useCreateGoal() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreGoalRequest) => (await unwrap(api.POST('/goals', { body }))).data,
    onSuccess: () => invalidateGoals(queryClient),
  })
}

export function useUpdateGoal() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateGoalRequest }) =>
      (await unwrap(api.PATCH('/goals/{goal}', { params: { path: { goal: id } }, body }))).data,
    onSuccess: () => invalidateGoals(queryClient),
  })
}

export function useDeleteGoal() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/goals/{goal}', { params: { path: { goal: id } } }))
    },
    onSuccess: () => invalidateGoals(queryClient),
  })
}

export function useGoalContributions(goalId: number) {
  return useQuery({
    queryKey: queryKeys.goalContributions(goalId),
    queryFn: async () =>
      (await unwrap(api.GET('/goals/{goal}/contributions', { params: { path: { goal: goalId } } }))).data,
  })
}

export function useCreateGoalContribution(goalId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreGoalContributionRequest) =>
      (await unwrap(api.POST('/goals/{goal}/contributions', { params: { path: { goal: goalId } }, body }))).data,
    onSuccess: () => invalidateGoals(queryClient),
  })
}

export function useDeleteGoalContribution(goalId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (contributionId: number) => {
      await expectOk(
        api.DELETE('/goals/{goal}/contributions/{contribution}', {
          params: { path: { goal: goalId, contribution: contributionId } },
        }),
      )
    },
    onSuccess: () => invalidateGoals(queryClient),
  })
}
