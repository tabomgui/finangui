import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'

type UpdateStatementRequest = components['schemas']['UpdateStatementRequest']
type PayStatementRequest = components['schemas']['PayStatementRequest']
type UpdateInstallmentPlanRequest = components['schemas']['UpdateInstallmentPlanRequest']

export function useCards(includeArchived = false) {
  return useQuery({
    queryKey: queryKeys.cards(includeArchived),
    queryFn: async () => (await unwrap(api.GET('/cards', { params: { query: { include_archived: includeArchived } } }))).data,
    placeholderData: keepPreviousData,
  })
}

export function useCard(id: number) {
  return useQuery({
    queryKey: queryKeys.card(id),
    queryFn: async () => (await unwrap(api.GET('/cards/{account}', { params: { path: { account: id } } }))).data,
  })
}

export function useCardStatements(cardId: number | null) {
  return useQuery({
    queryKey: queryKeys.cardStatements(cardId ?? 0),
    enabled: cardId !== null,
    queryFn: async () =>
      (await unwrap(api.GET('/cards/{account}/statements', { params: { path: { account: cardId ?? 0 } } }))).data,
  })
}

/**
 * Fatura em que um lançamento na data cairia (sem criar nada). Único uso é o texto auxiliar em
 * `card-entry-fields.tsx`, que troca de conta/data a cada tecla: `keepPreviousData` evita que o
 * texto pisque vazio enquanto a nova prévia carrega (o chamador decide quando a conta muda demais
 * para o valor anterior ainda fazer sentido).
 */
export function useStatementPreview(cardId: number | null, date: string | null) {
  return useQuery({
    queryKey: queryKeys.statementPreview(cardId ?? 0, date ?? ''),
    enabled: cardId !== null && date !== null && /^\d{4}-\d{2}-\d{2}$/.test(date),
    queryFn: async () =>
      (
        await unwrap(
          api.GET('/cards/{account}/statement-preview', {
            params: { path: { account: cardId ?? 0 }, query: { date: date ?? '' } },
          }),
        )
      ).data,
    placeholderData: keepPreviousData,
  })
}

export function useUpdateStatement() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateStatementRequest }) =>
      (await unwrap(api.PATCH('/card-statements/{statement}', { params: { path: { statement: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function usePayStatement() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: PayStatementRequest }) =>
      (await unwrap(api.POST('/card-statements/{statement}/payments', { params: { path: { statement: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useInstallmentPlans(cardId: number) {
  return useQuery({
    queryKey: queryKeys.installmentPlans(cardId),
    queryFn: async () =>
      (await unwrap(api.GET('/cards/{account}/installment-plans', { params: { path: { account: cardId } } }))).data,
  })
}

export function useUpdateInstallmentPlan() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: UpdateInstallmentPlanRequest }) =>
      (await unwrap(api.PATCH('/installment-plans/{plan}', { params: { path: { plan: id } }, body }))).data,
    onSuccess: () => invalidateLedger(queryClient),
  })
}

export function useCancelInstallmentPlan() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/installment-plans/{plan}', { params: { path: { plan: id } } }))
    },
    onSuccess: () => invalidateLedger(queryClient),
  })
}
