import { describe, expect, it } from 'vitest'
import { activeFilterCount, filtersFromParams, paramsWithFilter } from './filters'

describe('filtros na URL', () => {
  it('lê filtros válidos e ignora inválidos', () => {
    const params = new URLSearchParams('conta=3&categoria=x&tag=2&de=2026-10-01&ate=2026-13-40&tipo=in&busca=uber')

    expect(filtersFromParams(params)).toEqual({
      account_id: 3,
      tag_id: 2,
      from: '2026-10-01',
      direction: 'in',
      search: 'uber',
    })
  })

  it('grava e remove um filtro sem mexer nos outros', () => {
    const params = new URLSearchParams('conta=3&busca=uber')

    expect(paramsWithFilter(params, 'account_id', undefined).toString()).toBe('busca=uber')
    expect(paramsWithFilter(params, 'direction', 'out').get('tipo')).toBe('out')
  })

  it('conta filtros ativos fora a busca', () => {
    expect(activeFilterCount({ account_id: 1, search: 'x', from: '2026-10-01' })).toBe(2)
  })
})
