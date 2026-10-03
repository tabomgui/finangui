import { describe, expect, it } from 'vitest'
import { mergeTagIds, selectionKind } from './bulk'

describe('mergeTagIds', () => {
  it('adiciona sem duplicar', () => {
    expect(mergeTagIds([1, 2], 2)).toEqual([1, 2])
    expect(mergeTagIds([1], 3)).toEqual([1, 3])
  })
})

describe('selectionKind', () => {
  it('retorna "expense" quando todas as transações são saídas', () => {
    expect(selectionKind([{ direction: 'out' }, { direction: 'out' }])).toBe('expense')
  })

  it('retorna "income" quando todas as transações são entradas', () => {
    expect(selectionKind([{ direction: 'in' }, { direction: 'in' }])).toBe('income')
  })

  it('retorna null quando a seleção mistura entradas e saídas, inclusive pernas de transferência', () => {
    expect(selectionKind([{ direction: 'out' }, { direction: 'in' }])).toBeNull()
  })

  it('retorna null para seleção vazia', () => {
    expect(selectionKind([])).toBeNull()
  })
})
