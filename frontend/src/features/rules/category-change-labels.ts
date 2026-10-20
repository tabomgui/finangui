import type { ReconcileClearReason, ReviewGroupKey, RuleReconcile, RuleReconcileGroup } from '@/api/types'
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

/** Ordem em que a revisão mostra os grupos. */
export const REVIEW_GROUP_ORDER: readonly ReviewGroupKey[] = ['fill', 'conflict', 'stale', 'manual']

export const REVIEW_GROUP_LABELS: Record<ReviewGroupKey, string> = {
  fill: 'Sem categoria — as regras resolvem',
  conflict: 'Categoria automática diferente da regra',
  stale: 'Categorizados por regra que não se aplica mais',
  manual: 'Categorizados à mão com regra diferente',
}

export const REVIEW_GROUP_HINTS: Record<ReviewGroupKey, string> = {
  fill: 'Recebem a categoria da primeira regra que casa.',
  conflict: 'Histórico, banco ou outra regra deram uma categoria diferente da que as regras dão hoje.',
  stale: 'A regra foi excluída, desativada ou não casa mais: voltam para Categorizar.',
  manual: 'Só para conferir: o que você categorizou à mão nunca muda.',
}

/** Só a seleção de "Sem categoria" começa marcada; trocar ou tirar categoria é escolha explícita. */
export function reviewGroupCheckedByDefault(key: ReviewGroupKey): boolean {
  return key === 'fill'
}

export function launchCount(count: number): string {
  return `${count} ${pluralize(count, 'lançamento', 'lançamentos')}`
}
