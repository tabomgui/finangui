import { describe, expect, it } from 'vitest'
import { entryColor, fallbackCategoryColor } from './spending-colors'

describe('fallbackCategoryColor', () => {
  it('é determinística: o mesmo id sempre cai na mesma cor', () => {
    expect(fallbackCategoryColor(42)).toBe(fallbackCategoryColor(42))
  })

  it('categorias diferentes tendem a cores diferentes', () => {
    expect(fallbackCategoryColor(1)).not.toBe(fallbackCategoryColor(2))
  })

  it('"Sem categoria" (id nulo) sempre cai na mesma cor reservada', () => {
    expect(fallbackCategoryColor(null)).toBe(fallbackCategoryColor(null))
    expect(fallbackCategoryColor(null)).not.toBe(fallbackCategoryColor(1))
  })

  it('aceita ids grandes ou negativos sem explodir', () => {
    expect(() => fallbackCategoryColor(999999)).not.toThrow()
    expect(() => fallbackCategoryColor(-5)).not.toThrow()
  })
})

describe('entryColor', () => {
  it('usa a cor própria da categoria quando houver', () => {
    expect(entryColor(1, '#ff0000')).toBe('#ff0000')
  })

  it('cai no fallback determinístico quando a categoria não tem cor', () => {
    expect(entryColor(1, null)).toBe(fallbackCategoryColor(1))
  })
})
