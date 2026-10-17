import { pluralize } from '@/features/rules/rule-labels'

/** "N lançamentos sem categoria · M grupos", usada no aviso da Início e no subtítulo de `/categorizar`. */
export function pendingCategorizationLabel(totalPending: number, groupCount: number): string {
  const transactionsLabel = `${totalPending} ${pluralize(totalPending, 'lançamento sem categoria', 'lançamentos sem categoria')}`
  return `${transactionsLabel} · ${groupCount} ${pluralize(groupCount, 'grupo', 'grupos')}`
}
