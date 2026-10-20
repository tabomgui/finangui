import type { ReconcileClearReason, ReviewGroupKey, RuleReconcile, RuleReconcileGroup } from '@/api/types'
import { pluralize } from './rule-labels'

const CLEAR_LABELS: Record<ReconcileClearReason, string> = {
  no_longer_matches: 'Esta regra não pega mais: ficam sem categoria',
  no_category_action: 'A regra não define mais categoria: ficam sem categoria',
  inactive: 'Regra inativa: remover a categoria que ela deu',
}

/** Grupos da reconciliação com o que mudar; os vazios nem aparecem. */
export function nonEmptyReconcileGroups(reconcile: RuleReconcile): RuleReconcileGroup[] {
  return reconcile.groups.filter((group) => group.count > 0)
}

/**
 * Rótulo de um grupo da reconciliação de uma regra editada (`GET /rules/{rule}/reconcile`).
 * `categoryLabel`: caminho "Pai › Sub" da categoria que a regra define agora.
 */
export function reconcileGroupLabel(group: Pick<RuleReconcileGroup, 'key' | 'reason'>, categoryLabel: string): string {
  if (group.key === 'recategorize') return `Já categorizados por esta regra: passam para ${categoryLabel}`
  if (group.key === 'fill') return `Sem categoria que esta regra vai categorizar como ${categoryLabel}`
  return CLEAR_LABELS[group.reason ?? 'no_longer_matches']
}

/**
 * Marcado ao abrir a reconciliação: categorizar ou trocar para a categoria da regra, sim; tirar
 * categoria (`clear`, qualquer motivo) só se o usuário marcar.
 */
export function reconcileGroupCheckedByDefault(group: Pick<RuleReconcileGroup, 'key'>): boolean {
  return group.key !== 'clear'
}

/** Ordem em que a revisão mostra os grupos. */
export const REVIEW_GROUP_ORDER: readonly ReviewGroupKey[] = ['fill', 'conflict', 'stale', 'manual']

export const REVIEW_GROUP_LABELS: Record<ReviewGroupKey, string> = {
  fill: 'Sem categoria — as regras resolvem',
  conflict: 'Categoria automática diferente da regra',
  stale: 'Categorizados por regra que não se aplica mais',
  manual: 'Você escolheu outra categoria (nunca muda)',
}

export const REVIEW_GROUP_HINTS: Record<ReviewGroupKey, string> = {
  fill: 'Recebem a categoria da primeira regra que casa.',
  conflict: 'Histórico, banco ou outra regra deram uma categoria diferente da que as regras dão hoje.',
  stale: 'A regra foi excluída, desativada ou não casa mais: voltam para Categorizar.',
  manual: 'Só para conferir: as regras dariam outra categoria, mas o que você categorizou à mão nunca muda.',
}

/** Só a seleção de "Sem categoria" começa marcada; trocar ou tirar categoria é escolha explícita. */
export function reviewGroupCheckedByDefault(key: ReviewGroupKey): boolean {
  return key === 'fill'
}

export function launchCount(count: number): string {
  return `${count} ${pluralize(count, 'lançamento', 'lançamentos')}`
}
