import { describe, expect, it } from 'vitest'
import { accentInsensitiveFilter, normalizeSearch } from './search'

describe('normalizeSearch', () => {
  it('remove acentos e normaliza para minúsculas', () => {
    expect(normalizeSearch('Alimentação')).toBe('alimentacao')
    expect(normalizeSearch('Saúde')).toBe('saude')
  })
})

describe('accentInsensitiveFilter', () => {
  it('casa busca sem acento com valor acentuado', () => {
    expect(accentInsensitiveFilter('Alimentação', 'alimentacao')).toBeGreaterThan(0)
    expect(accentInsensitiveFilter('Saúde', 'saude')).toBeGreaterThan(0)
  })

  it('também casa via keywords', () => {
    expect(accentInsensitiveFilter('1', 'alimentacao', ['Alimentação'])).toBeGreaterThan(0)
  })

  it('não casa texto sem relação', () => {
    expect(accentInsensitiveFilter('Transporte', 'alimentacao')).toBe(0)
  })
})
