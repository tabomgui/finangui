import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  appToday,
  formatDate,
  formatDayLabel,
  formatDayMonth,
  formatMonth,
  isDateOnly,
  monthKey,
  monthName,
  monthRange,
  parseDateOnly,
  shiftMonth,
  toDateOnly,
} from './date'

describe('appToday', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('usa o fuso do app (America/Sao_Paulo), não o do navegador', () => {
    vi.useFakeTimers()
    // 2026-10-07T02:30:00Z é 2026-10-06T23:30 em America/Sao_Paulo (UTC-3): ainda dia 06.
    vi.setSystemTime(new Date('2026-10-07T02:30:00Z'))

    expect(appToday()).toBe('2026-10-06')
  })
})

describe('isDateOnly', () => {
  it('só reconhece o formato "YYYY-MM-DD", sem validar o calendário', () => {
    expect(isDateOnly('2026-10-01')).toBe(true)
    expect(isDateOnly('2026-13-40')).toBe(true)
    expect(isDateOnly('01/10/2026')).toBe(false)
    expect(isDateOnly('2026-10-01T00:00:00')).toBe(false)
    expect(isDateOnly('')).toBe(false)
  })
})

describe('parseDateOnly', () => {
  it('interpreta em horário local, sem deslocar o dia', () => {
    const date = parseDateOnly('2026-10-01')
    expect(date.getFullYear()).toBe(2026)
    expect(date.getMonth()).toBe(9)
    expect(date.getDate()).toBe(1)
  })

  it.each(['2026-13-01', '2026-02-30', '01/10/2026', ''])('rejeita %s', (value) => {
    expect(() => parseDateOnly(value)).toThrow()
  })
})

describe('formatação', () => {
  it('converte ida e volta', () => {
    expect(toDateOnly(parseDateOnly('2026-02-28'))).toBe('2026-02-28')
  })

  it('formata data curta brasileira', () => {
    expect(formatDate('2026-10-01')).toBe('01/10/2026')
  })

  it('rotula hoje, ontem e outros dias', () => {
    expect(formatDayLabel('2026-10-03', '2026-10-03')).toBe('Hoje')
    expect(formatDayLabel('2026-10-02', '2026-10-03')).toBe('Ontem')
    expect(formatDayLabel('2026-10-01', '2026-10-03')).toBe('Qui, 01 de out')
  })
})

describe('meses', () => {
  it('gera chave de mês', () => {
    expect(monthKey(parseDateOnly('2026-10-15'))).toBe('2026-10')
  })

  it('navega entre meses atravessando o ano', () => {
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
  })

  it('formata o nome do mês', () => {
    expect(formatMonth('2026-10')).toBe('Outubro de 2026')
  })

  it('calcula o intervalo de datas do mês, incluindo mês com 31 dias e fevereiro', () => {
    expect(monthRange('2026-10')).toEqual({ from: '2026-10-01', to: '2026-10-31' })
    expect(monthRange('2024-02')).toEqual({ from: '2024-02-01', to: '2024-02-29' })
  })

  it('formata dia/mês sem o ano', () => {
    expect(formatDayMonth('2026-10-31')).toBe('31/10')
  })

  it('da o nome do mes em minusculas, sem o ano', () => {
    expect(monthName('2026-11')).toBe('novembro')
  })
})
