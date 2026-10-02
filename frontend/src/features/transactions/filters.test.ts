import { describe, expect, it } from 'vitest'
import { today } from '@/lib/date'
import {
  activeFilterCount,
  effectiveTransactionFilters,
  filtersFromParams,
  paramsWithFilter,
  paramsWithFuture,
  showFutureFromParams,
} from './filters'

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

describe('futuros na URL', () => {
  it('só mostra lançamentos futuros com futuros=1', () => {
    expect(showFutureFromParams(new URLSearchParams(''))).toBe(false)
    expect(showFutureFromParams(new URLSearchParams('futuros=1'))).toBe(true)
    expect(showFutureFromParams(new URLSearchParams('futuros=0'))).toBe(false)
  })

  it('grava e remove o parâmetro sem mexer nos outros', () => {
    const params = new URLSearchParams('busca=uber')

    expect(paramsWithFuture(params, true).toString()).toBe('busca=uber&futuros=1')
    expect(paramsWithFuture(new URLSearchParams('busca=uber&futuros=1'), false).toString()).toBe('busca=uber')
  })
})

describe('effectiveTransactionFilters', () => {
  it('sem filtro de data e sem mostrar futuros, limita implicitamente a hoje', () => {
    expect(effectiveTransactionFilters({ account_id: 1 }, false)).toEqual({ account_id: 1, to: today() })
  })

  it('mostrando futuros, não limita a hoje', () => {
    expect(effectiveTransactionFilters({ account_id: 1 }, true)).toEqual({ account_id: 1 })
  })

  it('um "até" explícito sempre vence, mesmo sem mostrar futuros', () => {
    expect(effectiveTransactionFilters({ to: '2026-12-31' }, false)).toEqual({ to: '2026-12-31' })
  })
})
