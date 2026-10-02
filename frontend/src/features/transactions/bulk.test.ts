import { describe, expect, it, vi } from 'vitest'
import { mergeTagIds, runInBatches } from './bulk'

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
