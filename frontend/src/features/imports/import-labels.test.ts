import { describe, expect, it } from 'vitest'
import type { ImportPreview } from '@/api/types'
import { BATCH_STATUS_LABELS, BATCH_STATUS_VARIANT, FORMAT_OPTIONS, OUTCOME_LABELS, summaryText } from './import-labels'

function summary(overrides: Partial<ImportPreview['summary']>): ImportPreview['summary'] {
  return {
    new: 0,
    duplicate: 0,
    update: 0,
    replace_installment: 0,
    adopt: 0,
    swap_pending: 0,
    failed: 0,
    transfers_linked: 0,
    transfer_suggestions: 0,
    ...overrides,
  }
}

describe('OUTCOME_LABELS', () => {
  it('cobre todo resultado possível de uma linha', () => {
    expect(OUTCOME_LABELS).toEqual({
      new: 'Nova',
      duplicate: 'Já importada',
      update: 'Atualiza',
      replace_installment: 'Substitui parcela prevista',
      adopt: 'Casa com lançamento manual',
      swap_pending: 'Confirma pendente',
    })
  })
})

describe('FORMAT_OPTIONS', () => {
  it('lista os cinco formatos suportados com rótulo', () => {
    expect(FORMAT_OPTIONS.map((option) => option.value)).toEqual(['inter', 'nubank', 'nubank_card', 'c6', 'ofx'])
    expect(FORMAT_OPTIONS.find((option) => option.value === 'nubank_card')?.label).toBe('Nubank (cartão)')
  })
})

describe('BATCH_STATUS_LABELS', () => {
  it('traduz os três status de um lote', () => {
    expect(BATCH_STATUS_LABELS).toEqual({ pending: 'Pendente', completed: 'Importado', reverted: 'Revertido' })
  })
})

describe('BATCH_STATUS_VARIANT', () => {
  it('cobre os três status de um lote', () => {
    expect(BATCH_STATUS_VARIANT).toEqual({ pending: 'secondary', completed: 'default', reverted: 'outline' })
  })
})

describe('summaryText', () => {
  it('monta o texto só com os totais diferentes de zero, na ordem canônica', () => {
    expect(summaryText(summary({ new: 3, duplicate: 1, adopt: 1, failed: 1 }))).toBe(
      '3 novas · 1 já importada · 1 casada com lançamento manual · 1 inválida',
    )
  })

  it('pluraliza quando o total é maior que um', () => {
    expect(summaryText(summary({ new: 1, duplicate: 2, adopt: 2, failed: 2 }))).toBe(
      '1 nova · 2 já importadas · 2 casadas com lançamento manual · 2 inválidas',
    )
  })

  it('devolve string vazia quando não há nenhuma linha em nenhuma categoria', () => {
    expect(summaryText(summary({}))).toBe('')
  })

  it('inclui update e swap_pending quando presentes', () => {
    expect(summaryText(summary({ update: 1, swap_pending: 2 }))).toBe('1 atualizada · 2 pendentes confirmadas')
  })

  it('inclui transferências ligadas e sugeridas pela detecção automática do lote', () => {
    expect(summaryText(summary({ transfers_linked: 2, transfer_suggestions: 1 }))).toBe(
      '2 transferências ligadas · 1 sugestão de transferência',
    )
  })
})
