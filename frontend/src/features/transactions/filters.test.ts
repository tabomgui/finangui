import { describe, expect, it } from 'vitest'
import { today } from '@/lib/date'
import {
  activeFilterCount,
  effectiveTransactionFilters,
  filtersFromParams,
  paramsForFilters,
  paramsWithFilter,
  paramsWithFuture,
  reportableFilterLabel,
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

  it('lê category_exact, no_category, reportable e currency (flags "1"/moeda); category_exact fora da contagem, reportable/currency dentro', () => {
    const params = new URLSearchParams('categoria=5&exata=1&relatorio=1&moeda=BRL&tipo=out')

    expect(filtersFromParams(params)).toEqual({
      category_id: 5,
      category_exact: true,
      reportable: true,
      currency: 'BRL',
      direction: 'out',
    })
    expect(activeFilterCount(filtersFromParams(params))).toBe(4) // categoria + tipo + relatorio + moeda; exata não conta (modificador de categoria)
  })

  it('lê sem_categoria=1 como no_category', () => {
    expect(filtersFromParams(new URLSearchParams('sem_categoria=1'))).toEqual({ no_category: true })
  })

  it('ignora um valor de flag diferente de "1"', () => {
    expect(filtersFromParams(new URLSearchParams('exata=true'))).toEqual({})
  })

  it('normaliza exata sem categoria: descarta category_exact quando não há category_id', () => {
    expect(filtersFromParams(new URLSearchParams('exata=1'))).toEqual({})
  })

  it('normaliza categoria + sem_categoria ao mesmo tempo: sem_categoria vence, categoria (e exata) somem', () => {
    expect(filtersFromParams(new URLSearchParams('categoria=5&exata=1&sem_categoria=1'))).toEqual({ no_category: true })
  })
})

describe('reportableFilterLabel', () => {
  it('combina relatório e moeda numa só linha', () => {
    expect(reportableFilterLabel({ reportable: true, currency: 'BRL' })).toBe('Só lançamentos que entram em relatórios (BRL)')
  })

  it('só relatório, sem moeda', () => {
    expect(reportableFilterLabel({ reportable: true })).toBe('Só lançamentos que entram em relatórios')
  })

  it('só moeda, sem relatório', () => {
    expect(reportableFilterLabel({ currency: 'USD' })).toBe('(USD)')
  })

  it('null quando nenhum dos dois está ativo', () => {
    expect(reportableFilterLabel({})).toBeNull()
  })
})

describe('paramsForFilters', () => {
  it('monta os mesmos nomes curtos usados por filtersFromParams', () => {
    const filters = { category_id: 5, category_exact: true, reportable: true, currency: 'BRL', direction: 'out' as const, from: '2026-10-01', to: '2026-10-31' }

    const params = paramsForFilters(filters)

    expect(filtersFromParams(params)).toEqual(filters)
  })

  it('omite chaves ausentes em vez de gravá-las vazias', () => {
    expect(paramsForFilters({ account_id: 1 }).toString()).toBe('conta=1')
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
