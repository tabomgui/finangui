import type { ReconcileClearReason, RuleReconcile, RuleReconcileGroup } from '@/api/types'
import { pluralize } from './rule-labels'

const CLEAR_LABELS: Record<ReconcileClearReason, string> = {
  no_longer_matches: 'Categorizados por esta regra que não casam mais (voltam para Categorizar)',
  no_category_action: 'Categorizados por esta regra: a regra não define mais categoria (voltam para Categorizar)',
  inactive: 'Regra inativa: remover a categoria que ela deu',
}

/** Grupos da reconciliação com o que mudar; os vazios nem aparecem. */
export function nonEmptyReconcileGroups(reconcile: RuleReconcile): RuleReconcileGroup[] {
  return reconcile.groups.filter((group) => group.count > 0)
}

/** Rótulo de um grupo da reconciliação de uma regra editada (`GET /rules/{rule}/reconcile`). */
export function reconcileGroupLabel(group: Pick<RuleReconcileGroup, 'key' | 'reason'>): string {
  if (group.key === 'recategorize') return 'Categorizados por esta regra que mudam de categoria'
  if (group.key === 'fill') return 'Sem categoria que agora casam'
  return CLEAR_LABELS[group.reason ?? 'no_longer_matches']
}

/**
 * Marcado ao abrir a reconciliação: tudo, menos tirar a categoria que uma regra desativada deu —
 * desativar costuma ser "parar de categorizar daqui pra frente", não desfazer o passado.
 */
export function reconcileGroupCheckedByDefault(group: Pick<RuleReconcileGroup, 'key' | 'reason'>): boolean {
  return !(group.key === 'clear' && group.reason === 'inactive')
}

export function launchCount(count: number): string {
  return `${count} ${pluralize(count, 'lançamento', 'lançamentos')}`
}
