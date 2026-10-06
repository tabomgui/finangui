import { describe, expect, it } from 'vitest'
import { dayFromParam, monthFromParam } from './shares'

describe('monthFromParam', () => {
  it('aceita YYYY-MM válido e cai no mês atual caso contrário', () => {
    expect(monthFromParam('2026-09', '2026-10')).toBe('2026-09')
    expect(monthFromParam('2026-13', '2026-10')).toBe('2026-10')
    expect(monthFromParam(null, '2026-10')).toBe('2026-10')
  })
})

describe('dayFromParam', () => {
  it('aceita YYYY-MM-DD de uma data de calendário real, não futura', () => {
    // Bem no passado, pra não depender do relógio real da máquina que roda o teste.
    expect(dayFromParam('2000-01-01')).toBe('2000-01-01')
  })

  it('ignora qualquer coisa fora do formato "YYYY-MM-DD"', () => {
    expect(dayFromParam('2026-10')).toBeUndefined()
    expect(dayFromParam('não é data')).toBeUndefined()
    expect(dayFromParam(null)).toBeUndefined()
  })

  it('rejeita data de calendário inválida', () => {
    expect(dayFromParam('2026-02-30')).toBeUndefined()
  })

  it('rejeita data futura', () => {
    // Bem no futuro, pra não depender do relógio real da máquina que roda o teste.
    expect(dayFromParam('2099-01-01')).toBeUndefined()
  })
})
