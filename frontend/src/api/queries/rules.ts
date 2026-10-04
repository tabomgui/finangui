import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateRules, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'
import type { Rule, RuleBody } from '@/api/types'
import { notifyError } from '@/lib/form-errors'

type StoreRuleRequest = components['schemas']['StoreRuleRequest']
type UpdateRuleRequest = components['schemas']['UpdateRuleRequest']
type PreviewRuleRequest = components['schemas']['PreviewRuleRequest']
type ApplyRuleRequest = components['schemas']['ApplyRuleRequest']

export type CreateRuleBody = RuleBody & { name: string; is_active?: boolean }
export type UpdateRuleBody = Partial<RuleBody> & { name?: string; is_active?: boolean }
export type PreviewRuleBody = RuleBody & { overwrite?: boolean }

/**
 * O Scramble documenta o corpo de Store/Update/PreviewRuleRequest a partir das regras de validação
 * do FormRequest, que são deliberadamente soltas (o shape de fato é checado pelo
 * RuleDefinitionValidator no backend) — por isso o tipo gerado fica achatado (sem discriminar
 * condição de grupo) e com `value` só como string. `RuleBody` (em api/types.ts), derivado da
 * resposta (`RuleResource`, que não tem esse problema), documenta a forma precisa que o editor
 * de regras de fato monta. Um único cast na borda, aqui: o corpo precisamente tipado é compatível
 * em runtime (é o mesmo JSON), só não "bate" estruturalmente com o tipo gerado da requisição.
 */
function asRequestBody<T>(body: unknown): T {
  return body as T
}

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
    mutationFn: async (body: CreateRuleBody) =>
      (await unwrap(api.POST('/rules', { body: asRequestBody<StoreRuleRequest>(body) }))).data,
    // Semeia o cache do detalhe com a regra recém-criada: a tela de edição, para onde se navega
    // na sequência, já encontra os dados prontos em vez de pedir de novo e mostrar um spinner.
    onSuccess: (rule) => {
      queryClient.setQueryData(queryKeys.rule(rule.id), rule)
      return invalidateRules(queryClient)
    },
  })
}

export function useUpdateRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateRuleBody }) =>
      (await unwrap(api.PATCH('/rules/{rule}', { params: { path: { rule: id } }, body: asRequestBody<UpdateRuleRequest>(body) })))
        .data,
    // Grava a regra devolvida direto nos dois caches (detalhe e lista): a troca do switch "ativa"
    // aparece na hora, sem esperar o refetch do invalidate — que ainda roda, para cobrir outros
    // campos que a resposta deste PATCH não tenha os refletido (ex.: prioridade mudada em outro lugar).
    onSuccess: (rule) => {
      queryClient.setQueryData(queryKeys.rule(rule.id), rule)
      queryClient.setQueryData<Rule[]>(queryKeys.rules(), (current) => current?.map((item) => (item.id === rule.id ? rule : item)))
      return invalidateRules(queryClient)
    },
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

/**
 * Atualização otimista: a lista já aparece na nova ordem enquanto o PUT está em voo; se falhar,
 * desfaz e avisa. `scope` serializa chamadas concorrentes (ex.: dois arrastos em sequência rápida)
 * em vez de deixá-las corrigir uma por cima da outra.
 */
export function useReorderRules() {
  const queryClient = useQueryClient()
  return useMutation<void, Error, number[], ReorderContext>({
    scope: { id: 'rules-reorder' },
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
    onError: (error, _ids, context) => {
      if (context?.previous) queryClient.setQueryData(queryKeys.rules(), context.previous)
      notifyError(error)
    },
    onSettled: () => invalidateRules(queryClient),
  })
}

/**
 * Prévia síncrona de uma regra ainda não salva. `placeholderData` evita que a UI pisque vazia
 * enquanto o usuário ainda está ajustando condições/ações.
 */
export function useRulePreview(body: PreviewRuleBody, enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.rulePreview(body),
    queryFn: async () => (await unwrap(api.POST('/rules/preview', { body: asRequestBody<PreviewRuleRequest>(body) }))).data,
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
