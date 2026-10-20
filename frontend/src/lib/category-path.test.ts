import { describe, expect, it } from 'vitest'
import type { Category } from '@/api/types'
import { categoryPath, categoryPathById } from './category-path'

function category(overrides: Partial<Category>): Category {
  return {
    id: 1,
    parent_id: null,
    name: 'Alimentação',
    kind: 'expense',
    icon: null,
    color: null,
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
    ...overrides,
  }
}

const parent = category({ id: 1, name: 'Alimentação' })
const child = category({ id: 2, parent_id: 1, name: 'Mercado' })
const orphan = category({ id: 3, parent_id: 99, name: 'Feira' })
const byId = new Map([parent, child, orphan].map((item) => [item.id, item] as const))

describe('categoryPath', () => {
  it('mostra "Pai › Sub" quando o pai está carregado, senão só o nome', () => {
    expect(categoryPath(parent, byId)).toBe('Alimentação')
    expect(categoryPath(child, byId)).toBe('Alimentação › Mercado')
    expect(categoryPath(orphan, byId)).toBe('Feira')
  })
})

describe('categoryPathById', () => {
  it('sem id é "sem categoria"', () => {
    expect(categoryPathById(undefined, byId, false)).toBe('sem categoria')
    expect(categoryPathById(null, byId, true)).toBe('sem categoria')
  })

  it('id carregado vira o caminho', () => {
    expect(categoryPathById(2, byId, false)).toBe('Alimentação › Mercado')
  })

  it('id desconhecido: "…" enquanto carrega, "categoria excluída" depois', () => {
    expect(categoryPathById(50, new Map(), true)).toBe('…')
    expect(categoryPathById(50, byId, false)).toBe('categoria excluída')
  })
})
