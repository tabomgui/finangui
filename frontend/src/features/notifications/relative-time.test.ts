import { describe, expect, it } from 'vitest'
import { formatRelativeTime } from './relative-time'

const now = new Date('2026-10-04T12:00:00.000Z')

describe('formatRelativeTime', () => {
  it('minutos', () => {
    expect(formatRelativeTime('2026-10-04T11:55:00.000Z', now)).toBe('há 5 minutos')
  })

  it('horas', () => {
    expect(formatRelativeTime('2026-10-04T10:00:00.000Z', now)).toBe('há 2 horas')
  })

  it('dias', () => {
    expect(formatRelativeTime('2026-10-01T12:00:00.000Z', now)).toBe('há 3 dias')
  })

  it('segundos (menos de um minuto)', () => {
    expect(formatRelativeTime('2026-10-04T11:59:30.000Z', now)).toBe('há 30 segundos')
  })

  it('exatamente 1 dia: "ontem" (numeric: auto)', () => {
    expect(formatRelativeTime('2026-10-03T12:00:00.000Z', now)).toBe('ontem')
  })

  it('meses', () => {
    expect(formatRelativeTime('2026-08-04T12:00:00.000Z', now)).toBe('há 2 meses')
  })
})
