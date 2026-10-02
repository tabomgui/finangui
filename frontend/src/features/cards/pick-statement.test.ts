import { describe, expect, it } from 'vitest'
import { pickStatementId } from './pick-statement'

const list = [{ id: 1 }, { id: 2 }, { id: 3 }]

describe('pickStatementId', () => {
  it('usa a fatura da URL quando existe na lista', () => {
    expect(pickStatementId(list, '2', 3)).toBe(2)
  })
  it('sem URL válida, abre na atual (próxima a vencer)', () => {
    expect(pickStatementId(list, null, 3)).toBe(3)
    expect(pickStatementId(list, '99', 3)).toBe(3)
    expect(pickStatementId(list, 'abc', 3)).toBe(3)
  })
  it('sem atual, abre na mais recente', () => {
    expect(pickStatementId(list, null, null)).toBe(3)
  })
  it('sem faturas, nenhuma', () => {
    expect(pickStatementId([], null, null)).toBeNull()
  })
})
