import { describe, expect, it } from 'vitest'
import { reorderIds } from './reorder'

describe('reorderIds', () => {
  it('move o id ativo para a posição do id sobre o qual foi soltado', () => {
    expect(reorderIds([1, 2, 3, 4], 1, 3)).toEqual([2, 3, 1, 4])
  })

  it('não muda a lista se o id ativo ou o de destino não existir', () => {
    expect(reorderIds([1, 2, 3], 9, 2)).toEqual([1, 2, 3])
  })
})
