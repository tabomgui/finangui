import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateRules, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'
import type { Rule } from '@/api/types'

type StoreRuleRequest = components['schemas']['StoreRuleRequest']
type UpdateRuleRequest = components['schemas']['UpdateRuleRequest']
type PreviewRuleRequest = components['schemas']['PreviewRuleRequest']
type ApplyRuleRequest = components['schemas']['ApplyRuleRequest']

export function useRules() {
  return useQuery({
    queryKey: queryKeys.rules(),
    queryFn: async () => (await unwrap(api.GET('/rules'))).data,
  })
}

/** `refetchInterval` deixa quem chama acompanhar o job de aplicação retroativa (via `last_applied_at`). */
export function useRule(id: number, refetchInterval?: number) {
  return useQuery({
    queryKey: queryKeys.rule(id),
    queryFn: async () => (await unwrap(api.GET('/rules/{rule}', { params: { path: { rule: id } } }))).data,
    refetchInterval,
  })
}

export function useCreateRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: StoreRuleRequest) => (await unwrap(api.POST('/rules', { body }))).data,
    onSuccess: () => invalidateRules(queryClient),
  })
}

export function useUpdateRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateRuleRequest }) =>
      (await unwrap(api.PATCH('/rules/{rule}', { params: { path: { rule: id } }, body }))).data,
    onSuccess: () => invalidateRules(queryClient),
  })
}

export function useDeleteRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/rules/{rule}', { params: { path: { rule: id } } }))
    },
    onSuccess: () => invalidateRules(queryClient),
  })
}

type ReorderContext = { previous: Rule[] | undefined }

/** Atualização otimista: a lista já aparece na nova ordem enquanto o PUT está em voo. */
export function useReorderRules() {
  const queryClient = useQueryClient()
  return useMutation<void, Error, number[], ReorderContext>({
    mutationFn: async (ids: number[]) => {
      await expectOk(api.PUT('/rules/order', { body: { ids } }))
    },
    onMutate: async (ids) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.rules() })
      const previous = queryClient.getQueryData<Rule[]>(queryKeys.rules())
      if (previous) {
        const byId = new Map(previous.map((rule) => [rule.id, rule]))
        const reordered = ids.map((id) => byId.get(id)).filter((rule): rule is Rule => rule !== undefined)
        queryClient.setQueryData(queryKeys.rules(), reordered)
      }
      return { previous }
    },
    onError: (_error, _ids, context) => {
      if (context?.previous) queryClient.setQueryData(queryKeys.rules(), context.previous)
    },
    onSettled: () => invalidateRules(queryClient),
  })
}

/**
 * Prévia síncrona de uma regra ainda não salva. `placeholderData` evita que a UI pisque vazia
 * enquanto o usuário ainda está ajustando condições/ações.
 */
export function useRulePreview(body: PreviewRuleRequest, enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.rulePreview(body),
    queryFn: async () => (await unwrap(api.POST('/rules/preview', { body }))).data,
    enabled,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
}

/**
 * Dispara o job de aplicação retroativa. Não invalida o razão aqui: o job roda depois, em segundo
 * plano — quem chama acompanha o fim (ex.: via `useRule` com `refetchInterval`) e invalida então.
 */
export function useApplyRule() {
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: ApplyRuleRequest }) =>
      (await unwrap(api.POST('/rules/{rule}/apply', { params: { path: { rule: id } }, body }))).data,
  })
}
