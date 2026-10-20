import type { RuleReview, RuleReviewApplyItem, RuleReviewGroup } from '@/api/types'
import type { GroupCheckState } from '@/components/shared/group-checkbox'
import { reviewGroupCheckedByDefault } from './category-change-labels'

/** Seleção inicial da revisão: as linhas de "Sem categoria" (os grupos só informativos nunca entram). */
export function defaultReviewSelection(review: RuleReview): Set<number> {
  const ids = new Set<number>()
  for (const group of review.groups) {
    if (!group.applicable || !reviewGroupCheckedByDefault(group.key)) continue
    for (const item of group.items) ids.add(item.transaction_id)
  }
  return ids
}

export function reviewGroupCheckState(group: RuleReviewGroup, selectedIds: ReadonlySet<number>): GroupCheckState {
  const selected = group.items.filter((item) => selectedIds.has(item.transaction_id)).length
  if (selected === 0) return 'none'
  return selected === group.items.length ? 'all' : 'partial'
}

/** Marca o grupo inteiro carregado; se já estava todo marcado, desmarca. */
export function toggleReviewGroup(selectedIds: ReadonlySet<number>, group: RuleReviewGroup): Set<number> {
  const next = new Set(selectedIds)
  const all = reviewGroupCheckState(group, selectedIds) === 'all'
  for (const item of group.items) {
    if (all) next.delete(item.transaction_id)
    else next.add(item.transaction_id)
  }
  return next
}

/** Ids da seleção que ainda estão em algum grupo aplicável da resposta atual. */
export function pruneReviewSelection(selectedIds: ReadonlySet<number>, review: RuleReview): Set<number> {
  const loaded = new Set(review.groups.filter((group) => group.applicable).flatMap((group) => group.items.map((item) => item.transaction_id)))
  return new Set([...selectedIds].filter((id) => loaded.has(id)))
}

/**
 * Corpo de `POST /rules/review/apply`: cada linha marcada com a categoria nova que a tela mostrou,
 * ou sem `category_id` quando a revisão tira a categoria. Grupos não aplicáveis nunca entram.
 */
export function reviewApplyItems(review: RuleReview, selectedIds: ReadonlySet<number>): RuleReviewApplyItem[] {
  const items: RuleReviewApplyItem[] = []
  for (const group of review.groups) {
    if (!group.applicable) continue
    for (const item of group.items) {
      if (!selectedIds.has(item.transaction_id)) continue
      items.push(item.new_category_id === undefined ? { transaction_id: item.transaction_id } : { transaction_id: item.transaction_id, category_id: item.new_category_id })
    }
  }
  return items
}
