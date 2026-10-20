import { describe, expect, it } from 'vitest'
import type { CategoryChangeItem, RuleReview, RuleReviewGroup } from '@/api/types'
import { defaultReviewSelection, pruneReviewSelection, reviewApplyItems, reviewGroupCheckState, toggleReviewGroup } from './review-selection'

function item(id: number, overrides: Partial<CategoryChangeItem> = {}): CategoryChangeItem {
  return { transaction_id: id, date: '2026-09-01', description: `Lançamento ${id}`, account_name: 'Conta', amount: 100, direction: 'out', ...overrides }
}

function group(key: RuleReviewGroup['key'], items: CategoryChangeItem[]): RuleReviewGroup {
  return { key, count: items.length, applicable: key !== 'manual', truncated: false, items }
}

const review: RuleReview = {
  max_changes: 5000,
  groups: [
    group('fill', [item(1, { new_category_id: 10 }), item(2, { new_category_id: 10 })]),
    group('conflict', [item(3, { current_category_id: 11, new_category_id: 10 })]),
    group('stale', [item(4, { current_category_id: 11 })]),
    group('manual', [item(5, { current_category_id: 11, new_category_id: 10 })]),
  ],
}

describe('review-selection', () => {
  it('a seleção padrão é só "Sem categoria"', () => {
    expect([...defaultReviewSelection(review)]).toEqual([1, 2])
  })

  it('estado do "selecionar tudo": nenhum, parcial, todos', () => {
    const fill = review.groups[0]
    expect(reviewGroupCheckState(fill, new Set())).toBe('none')
    expect(reviewGroupCheckState(fill, new Set([1]))).toBe('partial')
    expect(reviewGroupCheckState(fill, new Set([1, 2]))).toBe('all')
  })

  it('alternar o grupo marca tudo e, já todo marcado, desmarca, sem mexer em outros grupos', () => {
    const fill = review.groups[0]
    expect([...toggleReviewGroup(new Set([1, 3]), fill)].sort()).toEqual([1, 2, 3])
    expect([...toggleReviewGroup(new Set([1, 2, 3]), fill)]).toEqual([3])
  })

  it('poda o que não está mais num grupo aplicável (inclusive um id do grupo à mão)', () => {
    expect([...pruneReviewSelection(new Set([1, 5, 99]), review)]).toEqual([1])
  })

  it('corpo da aplicação: category_id da categoria nova, sem ele para tirar a categoria, nunca o grupo à mão', () => {
    expect(reviewApplyItems(review, new Set([2, 3, 4, 5]))).toEqual([
      { transaction_id: 2, category_id: 10 },
      { transaction_id: 3, category_id: 10 },
      { transaction_id: 4 },
    ])
  })
})
