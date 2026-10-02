import { describe, expect, it } from 'vitest'
import { editKind } from './edit-kind'

describe('editKind', () => {
  it('transferência', () => {
    expect(editKind({ transfer_id: 'uuid', installment: null })).toBe('transfer')
  })

  it('parcela', () => {
    expect(editKind({ transfer_id: null, installment: { plan_id: 1, number: 2, total: 5 } })).toBe('installment')
  })

  it('lançamento comum', () => {
    expect(editKind({ transfer_id: null, installment: null })).toBe('entry')
  })
})
