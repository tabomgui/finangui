import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { invalidateCategories, invalidateLedger, invalidateRules, queryKeys, type PendingCategorizationFilters } from '@/api/query-keys'
import type { components } from '@/api/schema'

type ApplyCategorizationRequest = components['schemas']['ApplyCategorizationRequest']

/**
 * Contagem leve (sem carregar os lançamentos), usada no aviso da Início, no link de Transações e
 * no progresso da tela `/categorizar`. `options` deixa quem chama ajustar o refetch: a tela
 * `/categorizar` passa `refetchOnMount: 'always'` para tirar o baseline do progresso de uma
 * contagem fresca, mesmo com a do cache ainda dentro do `staleTime`.
 */
export function usePendingCategorizationCount(options: { refetchOnMount?: boolean | 'always' } = {}) {
  return useQuery({
    queryKey: queryKeys.pendingCategorizationCount(),
    queryFn: async () => (await unwrap(api.GET('/categorization/pending-count'))).data,
    ...options,
  })
}

/**
 * Pendentes agrupados por contraparte, para a tela `/categorizar`. `keepPreviousData` evita o
 * flash de skeleton a cada busca/filtro: a lista anterior continua visível, com
 * `isPlaceholderData` ligado, até a nova chegar. A tela usa esse sinal para não podar a seleção
 * com base na lista antiga e para esmaecer a lista enquanto isso.
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
