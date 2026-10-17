import { describe, expect, it } from 'vitest'
import {
  groupCheckState,
  hasPartialGroupSelection,
  selectGroupDirection,
  selectionKind,
  toggleGroupSelection,
  type SelectableGroup,
} from './selection'

function group(key: string, transactions: SelectableGroup['transactions']): SelectableGroup {
  return { key, transactions }
}

describe('groupCheckState', () => {
  it('nenhum lançamento marcado: "none"', () => {
    const g = group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'out' }])

    expect(groupCheckState(g, new Set())).toBe('none')
  })

  it('todos os lançamentos marcados: "all"', () => {
    const g = group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'out' }])

    expect(groupCheckState(g, new Set([1, 2]))).toBe('all')
  })

  it('só parte dos lançamentos marcados: "partial"', () => {
    const g = group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'out' }])

    expect(groupCheckState(g, new Set([1]))).toBe('partial')
  })

  it('grupo sem lançamento nenhum: "none"', () => {
    expect(groupCheckState(group('a', []), new Set([1]))).toBe('none')
  })
})

describe('toggleGroupSelection', () => {
  it('marca todos os lançamentos do grupo, inclusive os que já estavam fora da seleção', () => {
    const g = group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'in' }, { id: 3, direction: 'out' }])

    const next = toggleGroupSelection(new Set(), g)

    expect(next).toEqual(new Set([1, 2, 3]))
  })

  it('desmarca o grupo inteiro quando já estava todo marcado', () => {
    const g = group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'in' }])

    const next = toggleGroupSelection(new Set([1, 2]), g)

    expect(next).toEqual(new Set())
  })

  it('marca o resto do grupo quando a seleção estava parcial', () => {
    const g = group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'in' }])

    const next = toggleGroupSelection(new Set([1]), g)

    expect(next).toEqual(new Set([1, 2]))
  })

  it('preserva a seleção de outros grupos', () => {
    const g = group('a', [{ id: 1, direction: 'out' }])

    const next = toggleGroupSelection(new Set([99]), g)

    expect(next).toEqual(new Set([99, 1]))
  })
})

describe('selectGroupDirection', () => {
  const g = group('a', [
    { id: 1, direction: 'out' },
    { id: 2, direction: 'in' },
    { id: 3, direction: 'out' },
  ])

  it('"só saídas" substitui a seleção do grupo pelas saídas', () => {
    const next = selectGroupDirection(new Set([2]), g, 'out')

    expect(next).toEqual(new Set([1, 3]))
  })

  it('"só entradas" substitui a seleção do grupo pelas entradas', () => {
    const next = selectGroupDirection(new Set([1, 3]), g, 'in')

    expect(next).toEqual(new Set([2]))
  })

  it('não mexe na seleção de outros grupos', () => {
    const next = selectGroupDirection(new Set([99, 1]), g, 'in')

    expect(next).toEqual(new Set([99, 2]))
  })
})

describe('selectionKind', () => {
  it('nada selecionado: null', () => {
    expect(selectionKind([])).toBeNull()
  })

  it('só saídas: "expense"', () => {
    expect(selectionKind([{ id: 1, direction: 'out' }, { id: 2, direction: 'out' }])).toBe('expense')
  })

  it('só entradas: "income"', () => {
    expect(selectionKind([{ id: 1, direction: 'in' }])).toBe('income')
  })

  it('saídas e entradas juntas: "mixed"', () => {
    expect(selectionKind([{ id: 1, direction: 'out' }, { id: 2, direction: 'in' }])).toBe('mixed')
  })
})

describe('hasPartialGroupSelection', () => {
  it('verdadeiro quando algum grupo está parcialmente marcado', () => {
    const groups = [group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'out' }]), group('b', [{ id: 3, direction: 'in' }])]

    expect(hasPartialGroupSelection(groups, new Set([1, 3]))).toBe(true)
  })

  it('com direção, só os lançamentos dessa direção contam ("só saídas" num grupo misto não é parcial)', () => {
    const mixed = group('a', [
      { id: 1, direction: 'out' },
      { id: 2, direction: 'out' },
      { id: 3, direction: 'in' },
    ])

    expect(hasPartialGroupSelection([mixed], new Set([1, 2]))).toBe(true)
    expect(hasPartialGroupSelection([mixed], new Set([1, 2]), 'out')).toBe(false)
    expect(hasPartialGroupSelection([mixed], new Set([1]), 'out')).toBe(true)
  })

  it('falso quando todo grupo está completo ou vazio', () => {
    const groups = [group('a', [{ id: 1, direction: 'out' }, { id: 2, direction: 'out' }]), group('b', [{ id: 3, direction: 'in' }])]

    expect(hasPartialGroupSelection(groups, new Set([1, 2]))).toBe(false)
  })

  it('falso quando nada está selecionado', () => {
    const groups = [group('a', [{ id: 1, direction: 'out' }])]

    expect(hasPartialGroupSelection(groups, new Set())).toBe(false)
  })
})
