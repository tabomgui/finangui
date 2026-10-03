import { describe, expect, it } from 'vitest'
import { CONNECTION_STATUS_LABELS, syncedLabel } from './connection-labels'

describe('syncedLabel', () => {
  const reference = new Date('2026-10-03T12:00:00Z')

  it('nunca sincronizado quando não há data', () => {
    expect(syncedLabel(null, reference)).toBe('Nunca sincronizado')
  })

  it('fala em minutos para menos de uma hora', () => {
    expect(syncedLabel('2026-10-03T11:55:00Z', reference)).toBe('Sincronizado há 5 min')
  })

  it('fala "agora" para menos de um minuto', () => {
    expect(syncedLabel('2026-10-03T11:59:40Z', reference)).toBe('Sincronizado agora')
  })

  it('fala em horas entre uma e vinte e quatro horas', () => {
    expect(syncedLabel('2026-10-03T09:00:00Z', reference)).toBe('Sincronizado há 3 h')
  })

  it('fala em dias a partir de vinte e quatro horas', () => {
    expect(syncedLabel('2026-10-01T12:00:00Z', reference)).toBe('Sincronizado há 2 dias')
    expect(syncedLabel('2026-10-02T12:00:00Z', reference)).toBe('Sincronizado há 1 dia')
  })
})

it('tem rótulo para todo status de conexão', () => {
  expect(CONNECTION_STATUS_LABELS).toEqual({
    pending_link: 'Aguardando vínculo',
    active: 'Conectado',
    needs_reauth: 'Reconectar',
    error: 'Erro na sincronização',
  })
})
