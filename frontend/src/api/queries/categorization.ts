import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { invalidateCategories, invalidateLedger, invalidateRules, queryKeys, type PendingCategorizationFilters } from '@/api/query-keys'
import type { components } from '@/api/schema'
import type { RuleConditionNodeInput } from '@/api/types'

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
 * Regra opcional do apply, na forma precisa que a tela monta. O tipo gerado para
 * `ApplyCategorizationRequest.rule` vem das regras de validação, achatadas como as de `/rules`
 * (sem discriminar condição de grupo, `value` só string) — ver `asRequestBody` em `rules.ts`: o
 * JSON é o mesmo, só não "bate" estruturalmente com o tipo gerado, daí o único cast abaixo.
 */
export type ApplyCategorizationRule = {
  name: string
  match: 'all' | 'any'
  conditions: RuleConditionNodeInput[]
  apply_to_pending: boolean
}

/**
 * `categoryCreated` é informado por quem chama quando a categoria aplicada acabou de ser criada
 * na hora (pelo `CategoryPicker`, com a opção "Criar…"): só nesse caso `invalidateCategories`
 * entra, para não invalidar a lista de categorias à toa a cada aplicação.
 */
export type ApplyCategorizationVariables = Omit<ApplyCategorizationRequest, 'rule'> & {
  rule?: ApplyCategorizationRule
  categoryCreated?: boolean
}

export function useApplyCategorization() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ transaction_ids, category_id, rule }: ApplyCategorizationVariables) =>
      (await unwrap(api.POST('/categorization/apply', { body: { transaction_ids, category_id, rule: rule as ApplyCategorizationRequest['rule'] } })))
        .data,
    // `invalidateLedger` já cobre `categorization` (pendentes e contagem) — ver query-keys.ts.
    onSuccess: (_result, variables) => {
      void invalidateLedger(queryClient)
      void invalidateRules(queryClient)
      if (variables.categoryCreated) void invalidateCategories(queryClient)
    },
  })
}
