import { describe, expect, it } from 'vitest'
import { addedAndUpdatedLabel, formatRunDateTime, formatRunDuration, STATUS_META, TRIGGER_LABELS, warningOrErrorText } from './sync-run-labels'

describe('TRIGGER_LABELS', () => {
  it('traduz os quatro gatilhos', () => {
    expect(TRIGGER_LABELS.scheduled).toBe('Agendada')
    expect(TRIGGER_LABELS.manual).toBe('Manual')
    expect(TRIGGER_LABELS.connect).toBe('Conexão')
    expect(TRIGGER_LABELS.credentials).toBe('Credenciais')
  })
})

describe('STATUS_META', () => {
  it('tem rótulo e ícone para os quatro status', () => {
    expect(STATUS_META.running.label).toBe('Em andamento')
    expect(STATUS_META.success.label).toBe('Sucesso')
    expect(STATUS_META.partial.label).toBe('Com avisos')
    expect(STATUS_META.error.label).toBe('Erro')
  })
})

describe('formatRunDateTime', () => {
  it('formata no padrão "dd/MM/yyyy às HH:mm" (hora local, não fixa um fuso)', () => {
    expect(formatRunDateTime('2026-10-05T14:32:00-03:00')).toMatch(/^\d{2}\/\d{2}\/2026 às \d{2}:\d{2}$/)
  })
})

describe('formatRunDuration', () => {
  it('retorna null sem finished_at (run em andamento)', () => {
    expect(formatRunDuration('2026-10-05T14:32:00Z')).toBeNull()
  })

  it('mostra segundos quando dura menos de um minuto', () => {
    expect(formatRunDuration('2026-10-05T14:32:00Z', '2026-10-05T14:32:42Z')).toBe('42s')
  })

  it('mostra minutos e segundos', () => {
    expect(formatRunDuration('2026-10-05T14:32:00Z', '2026-10-05T14:33:15Z')).toBe('1min 15s')
  })

  it('omite os segundos quando são zero', () => {
    expect(formatRunDuration('2026-10-05T14:32:00Z', '2026-10-05T14:34:00Z')).toBe('2min')
  })

  it('mostra horas e minutos para durações bem longas', () => {
    expect(formatRunDuration('2026-10-05T10:00:00Z', '2026-10-05T11:05:00Z')).toBe('1h 5min')
  })
})

describe('addedAndUpdatedLabel', () => {
  it('só adicionados quando não há atualizados', () => {
    expect(addedAndUpdatedLabel({ added_count: 1, updated_count: 0 })).toBe('1 adicionado')
    expect(addedAndUpdatedLabel({ added_count: 5, updated_count: 0 })).toBe('5 adicionados')
  })

  it('inclui atualizados quando > 0', () => {
    expect(addedAndUpdatedLabel({ added_count: 5, updated_count: 1 })).toBe('5 adicionados · 1 atualizado')
    expect(addedAndUpdatedLabel({ added_count: 0, updated_count: 3 })).toBe('0 adicionados · 3 atualizados')
  })
})

describe('warningOrErrorText', () => {
  it('mostra o erro quando status é error', () => {
    expect(warningOrErrorText({ status: 'error', error: 'Credencial recusada pelo banco.', warnings: [] })).toBe(
      'Credencial recusada pelo banco.',
    )
  })

  it('mostra os avisos juntados quando status é partial', () => {
    expect(
      warningOrErrorText({ status: 'partial', error: null, warnings: ['Não foi possível pedir atualização ao banco.'] }),
    ).toBe('Não foi possível pedir atualização ao banco.')
  })

  it('retorna null quando não há nada a mostrar', () => {
    expect(warningOrErrorText({ status: 'success', error: null, warnings: [] })).toBeNull()
  })
})
