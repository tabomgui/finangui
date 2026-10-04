import { describe, expect, it } from 'vitest'
import { firstPageSuggestionsCount, suggestionsCountLabel } from './suggestions-count'

describe('firstPageSuggestionsCount', () => {
  it('sem página ainda (carregando), conta zero sem mais', () => {
    expect(firstPageSuggestionsCount(undefined)).toEqual({ count: 0, hasMore: false })
  })

  it('conta os itens da página e hasMore vem do next_cursor dela', () => {
    expect(firstPageSuggestionsCount({ data: [{}, {}], meta: { next_cursor: null } })).toEqual({ count: 2, hasMore: false })
    expect(firstPageSuggestionsCount({ data: [{}, {}], meta: { next_cursor: 'abc' } })).toEqual({ count: 2, hasMore: true })
  })
})

describe('suggestionsCountLabel', () => {
  it('singular exato, sem "+"', () => {
    expect(suggestionsCountLabel({ count: 1, hasMore: false })).toBe('1 sugestão de transferência')
  })

  it('plural com mais de uma', () => {
    expect(suggestionsCountLabel({ count: 2, hasMore: false })).toBe('2 sugestões de transferência')
  })

  it('com mais páginas, usa "N+" e plural mesmo se count for 1', () => {
    expect(suggestionsCountLabel({ count: 1, hasMore: true })).toBe('1+ sugestões de transferência')
  })
})
