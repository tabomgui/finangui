import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { invalidateCategories, invalidateCategorization, invalidateLedger, invalidateRules, queryKeys, type PendingCategorizationFilters } from '@/api/query-keys'
import type { components } from '@/api/schema'

type ApplyCategorizationRequest = components['schemas']['ApplyCategorizationRequest']

/** Contagem leve (sem carregar os lançamentos), usada no aviso da Início e no link de Transações. */
export function usePendingCategorizationCount() {
  return useQuery({
    queryKey: queryKeys.pendingCategorizationCount(),
    queryFn: async () => (await unwrap(api.GET('/categorization/pending-count'))).data,
  })
}

/** Pendentes agrupados por contraparte, para a tela `/categorizar`. */
export function usePendingCategorization(filters: PendingCategorizationFilters = {}) {
  return useQuery({
    queryKey: queryKeys.pendingCategorization(filters),
    queryFn: async () => (await unwrap(api.GET('/categorization/pending', { params: { query: filters } }))).data,
  })
}

/**
 * `categoryCreated` é informado por quem chama quando a categoria aplicada acabou de ser criada
 * pelo `CategoryPicker` (fluxo da barra de ação completa, numa tarefa seguinte): só nesse caso
 * `invalidateCategories` entra, para não invalidar a lista de categorias à toa a cada aplicação.
 */
type ApplyCategorizationVariables = ApplyCategorizationRequest & { categoryCreated?: boolean }

export function useApplyCategorization() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ transaction_ids, category_id, rule }: ApplyCategorizationVariables) =>
      (await unwrap(api.POST('/categorization/apply', { body: { transaction_ids, category_id, rule } }))).data,
    onSuccess: (_result, variables) => {
      void invalidateCategorization(queryClient)
      void invalidateLedger(queryClient)
      void invalidateRules(queryClient)
      if (variables.categoryCreated) void invalidateCategories(queryClient)
    },
  })
}
