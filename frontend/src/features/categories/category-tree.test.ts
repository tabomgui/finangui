import { describe, expect, it } from 'vitest'
import type { Category } from '@/api/types'
import { buildCategoryTree, flattenCategoryOptions } from './category-tree'

function category(overrides: Partial<Category>): Category {
  return {
    id: 1,
    parent_id: null,
    name: 'X',
    kind: 'expense',
    icon: null,
    color: null,
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
    ...overrides,
  }
}

const categories: Category[] = [
  category({ id: 1, name: 'Moradia' }),
  category({ id: 2, name: 'Aluguel', parent_id: 1 }),
  category({ id: 3, name: 'Alimentação' }),
  category({ id: 4, name: 'Mercado', parent_id: 3 }),
  category({ id: 5, name: 'Delivery', parent_id: 3, is_archived: true }),
  category({ id: 6, name: 'Salário', kind: 'income' }),
]

describe('buildCategoryTree', () => {
  it('agrupa por pai, filtra por tipo e ordena por nome', () => {
    const tree = buildCategoryTree(categories, 'expense')

    expect(tree.map((node) => node.category.name)).toEqual(['Alimentação', 'Moradia'])
    expect(tree[0].children.map((child) => child.name)).toEqual(['Delivery', 'Mercado'])
  })
})

describe('flattenCategoryOptions', () => {
  it('lista pais seguidos dos filhos, sem arquivadas', () => {
    const options = flattenCategoryOptions(categories, { kind: 'expense' })

    expect(options.map((option) => [option.category.name, option.depth])).toEqual([
      ['Alimentação', 0],
      ['Mercado', 1],
      ['Moradia', 0],
      ['Aluguel', 1],
    ])
  })

  it('mantém a categoria selecionada mesmo arquivada', () => {
    const options = flattenCategoryOptions(categories, { kind: 'expense', keepId: 5 })

    expect(options.some((option) => option.category.id === 5)).toBe(true)
  })

  it('sem tipo, inclui todas', () => {
    expect(flattenCategoryOptions(categories, {}).some((option) => option.category.name === 'Salário')).toBe(true)
  })
})
