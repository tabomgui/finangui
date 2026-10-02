import { describe, expect, it, vi } from 'vitest'
import { mergeTagIds, runInBatches, selectionKind } from './bulk'

describe('mergeTagIds', () => {
  it('adiciona sem duplicar', () => {
    expect(mergeTagIds([1, 2], 2)).toEqual([1, 2])
    expect(mergeTagIds([1], 3)).toEqual([1, 3])
  })
})

describe('runInBatches', () => {
  it('executa todas as tarefas com concorrência limitada e separa falhas', async () => {
    let running = 0
    let peak = 0
    const task = vi.fn(async (id: number) => {
      running += 1
      peak = Math.max(peak, running)
      await new Promise((resolve) => setTimeout(resolve, 1))
      running -= 1
      if (id === 3) throw new Error('falhou')
    })

    const result = await runInBatches([1, 2, 3, 4, 5], task, 2)

    expect(task).toHaveBeenCalledTimes(5)
    expect(peak).toBeLessThanOrEqual(2)
    expect(result).toEqual({ succeeded: [1, 2, 4, 5], failed: [3] })
  })
})

describe('selectionKind', () => {
  it('retorna "expense" quando todas as transações são saídas', () => {
    expect(selectionKind([{ direction: 'out' }, { direction: 'out' }])).toBe('expense')
  })

  it('retorna "income" quando todas as transações são entradas', () => {
    expect(selectionKind([{ direction: 'in' }, { direction: 'in' }])).toBe('income')
  })

  it('retorna null quando a seleção mistura entradas e saídas, inclusive pernas de transferência', () => {
    expect(selectionKind([{ direction: 'out' }, { direction: 'in' }])).toBeNull()
  })

  it('retorna null para seleção vazia', () => {
    expect(selectionKind([])).toBeNull()
  })
})
