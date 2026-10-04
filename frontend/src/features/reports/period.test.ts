import { describe, expect, it } from 'vitest'
import { evolutionRange, monthAxisLabel } from './period'

describe('evolutionRange', () => {
  it('últimos 6 meses termina no mês atual e cobre 6 meses', () => {
    expect(evolutionRange('last6', '2026-10')).toEqual({ from: '2026-05', to: '2026-10' })
  })

  it('últimos 12 meses termina no mês atual e cobre 12 meses', () => {
    expect(evolutionRange('last12', '2026-10')).toEqual({ from: '2025-11', to: '2026-10' })
  })

  it('ano atual vai de janeiro até o mês atual', () => {
    expect(evolutionRange('year', '2026-10')).toEqual({ from: '2026-01', to: '2026-10' })
  })

  it('últimos 6 meses atravessa a virada do ano', () => {
    expect(evolutionRange('last6', '2026-02')).toEqual({ from: '2025-09', to: '2026-02' })
  })
})

describe('monthAxisLabel', () => {
  it('abrevia mês e ano com duas casas', () => {
    expect(monthAxisLabel('2026-10')).toBe('out/26')
    expect(monthAxisLabel('2026-01')).toBe('jan/26')
  })
})
