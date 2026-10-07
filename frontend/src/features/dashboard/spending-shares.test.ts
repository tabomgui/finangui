import { describe, expect, it } from 'vitest'
import type { SpendingCategory, SpendingChild } from '@/api/types'
import { childViewEntries, entryLabel, entryTransactionFilters, rootViewEntries, transactionCountLabel } from './spending-shares'

function category(overrides: Partial<SpendingCategory> = {}): SpendingCategory {
  return { category_id: 1, name: 'Alimentação', color: null, icon: null, amount: 30000, count: 3, children: [], ...overrides }
}

function child(overrides: Partial<SpendingChild> = {}): SpendingChild {
  return { category_id: 2, name: 'Mercado', color: null, icon: null, amount: 20000, count: 2, direct: false, ...overrides }
}

describe('rootViewEntries', () => {
  it('calcula o percentual (número e rótulo) sobre o total e marca hasChildren', () => {
    const entries = rootViewEntries([category({ amount: 30000, children: [child()] }), category({ category_id: undefined, name: 'Sem categoria', amount: 10000 })], 40000)

    expect(entries).toEqual([
      {
        key: '1',
        categoryId: 1,
        name: 'Alimentação',
        color: null,
        displayColor: expect.stringMatching(/^#[0-9a-f]{6}$/),
        icon: null,
        amount: 30000,
        count: 3,
        direct: false,
        hasChildren: true,
        percentLabel: '75%',
      },
      {
        key: 'none',
        categoryId: null,
        name: 'Sem categoria',
        color: null,
        displayColor: expect.stringMatching(/^#[0-9a-f]{6}$/),
        icon: null,
        amount: 10000,
        count: 3,
        direct: false,
        hasChildren: false,
        percentLabel: '25%',
      },
    ])
  })

  it('lida com total zero sem dividir por zero', () => {
    expect(rootViewEntries([], 0)).toEqual([])
  })

  it('usa a cor própria da categoria como displayColor quando houver', () => {
    const entries = rootViewEntries([category({ color: '#123456' })], 30000)
    expect(entries[0].displayColor).toBe('#123456')
  })

  it('"Sem categoria" sempre com a mesma displayColor reservada', () => {
    const [entry] = rootViewEntries([category({ category_id: undefined, name: 'Sem categoria' })], 30000)
    expect(entry.displayColor).toBe('#6b7280')
  })

  it('fatia pequena mas não nula mostra "<1%", nunca "0%"', () => {
    const entries = rootViewEntries([category({ amount: 1 }), category({ category_id: 2, amount: 999999 })], 1000000)
    expect(entries[0].percentLabel).toBe('<1%')
  })
})

describe('childViewEntries', () => {
  it('distingue a entrada "direto no pai" com uma chave própria, mesmo repetindo o id do pai', () => {
    const entries = childViewEntries(
      [child({ category_id: 2, amount: 30000 }), child({ category_id: 1, name: 'Alimentação', amount: 10000, direct: true })],
      40000,
    )

    expect(entries.map((e) => e.key)).toEqual(['2', '1-direct'])
    expect(entries.every((e) => e.hasChildren === false)).toBe(true)
    expect(entries[1].percentLabel).toBe('25%')
  })
})

describe('entryLabel', () => {
  it('sufixa "(direto)" só na entrada direta', () => {
    expect(entryLabel({ name: 'Alimentação', direct: true })).toBe('Alimentação (direto)')
    expect(entryLabel({ name: 'Mercado', direct: false })).toBe('Mercado')
  })
})

describe('transactionCountLabel', () => {
  it('singular com 1, plural com qualquer outra quantidade', () => {
    expect(transactionCountLabel(1)).toBe('1 lançamento')
    expect(transactionCountLabel(2)).toBe('2 lançamentos')
    expect(transactionCountLabel(0)).toBe('0 lançamentos')
  })
})

describe('entryTransactionFilters', () => {
  const context = { from: '2026-10-01', to: '2026-10-31', currency: 'BRL', isChildLevel: false }

  it('categoria de topo: category_id sem category_exact (inclui subcategorias)', () => {
    const entry = rootViewEntries([category({ category_id: 5 })], 30000)[0]

    expect(entryTransactionFilters(entry, context)).toEqual({
      reportable: true,
      currency: 'BRL',
      direction: 'out',
      from: '2026-10-01',
      to: '2026-10-31',
      category_id: 5,
    })
  })

  it('subcategoria ou "direto no pai": category_id com category_exact', () => {
    const entry = childViewEntries([child({ category_id: 7 })], 20000)[0]

    expect(entryTransactionFilters(entry, { ...context, isChildLevel: true })).toEqual({
      reportable: true,
      currency: 'BRL',
      direction: 'out',
      from: '2026-10-01',
      to: '2026-10-31',
      category_id: 7,
      category_exact: true,
    })
  })

  it('"Sem categoria": no_category, nunca category_id', () => {
    const entry = rootViewEntries([category({ category_id: undefined, name: 'Sem categoria' })], 30000)[0]

    expect(entryTransactionFilters(entry, context)).toEqual({
      reportable: true,
      currency: 'BRL',
      direction: 'out',
      from: '2026-10-01',
      to: '2026-10-31',
      no_category: true,
    })
  })
})
