import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { invalidateCategories, invalidateLedger, invalidateRules, queryKeys, type PendingCategorizationFilters } from '@/api/query-keys'
import type { components } from '@/api/schema'

type ApplyCategorizationRequest = components['schemas']['ApplyCategorizationRequest']

/** Contagem leve (sem carregar os lançamentos), usada no aviso da Início e no link de Transações. */
export function usePendingCategorizationCount() {
  return useQuery({
    queryKey: queryKeys.pendingCategorizationCount(),
    queryFn: async () => (await unwrap(api.GET('/categorization/pending-count'))).data,
  })
}

/**
 * Pendentes agrupados por contraparte, para a tela `/categorizar`. `keepPreviousData` evita o
 * flash de skeleton a cada busca/filtro: a lista anterior continua visível (ver
 * `isPlaceholderData`, usado tanto pelo estado de carregamento quanto para não podar a seleção
 * com base numa resposta que ainda é a antiga).
 */
export function usePendingCategorization(filters: PendingCategorizationFilters = {}) {
  return useQuery({
    queryKey: queryKeys.pendingCategorization(filters),
    queryFn: async () => (await unwrap(api.GET('/categorization/pending', { params: { query: filters } }))).data,
    placeholderData: keepPreviousData,
  })
}

/**
 * `categoryCreated` é informado por quem chama quando a categoria aplicada acabou de ser criada
 * na hora (pelo `CategoryPicker`, com a opção "Criar…"): só nesse caso `invalidateCategories`
 * entra, para não invalidar a lista de categorias à toa a cada aplicação.
 */
type ApplyCategorizationVariables = ApplyCategorizationRequest & { categoryCreated?: boolean }

export function useApplyCategorization() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ transaction_ids, category_id, rule }: ApplyCategorizationVariables) =>
      (await unwrap(api.POST('/categorization/apply', { body: { transaction_ids, category_id, rule } }))).data,
    // `invalidateLedger` já cobre `categorization` (pendentes e contagem) — ver query-keys.ts.
    onSuccess: (_result, variables) => {
      void invalidateLedger(queryClient)
      void invalidateRules(queryClient)
      if (variables.categoryCreated) void invalidateCategories(queryClient)
    },
  })
}
