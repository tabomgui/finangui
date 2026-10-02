import { describe, expect, it } from 'vitest'
import { groupByDay } from './group-by-day'

describe('groupByDay', () => {
  it('agrupa itens consecutivos da mesma data preservando a ordem', () => {
    const items = [
      { id: 1, date: '2026-10-03' },
      { id: 2, date: '2026-10-03' },
      { id: 3, date: '2026-10-01' },
    ]

    expect(groupByDay(items)).toEqual([
      { date: '2026-10-03', items: [items[0], items[1]] },
      { date: '2026-10-01', items: [items[2]] },
    ])
  })
})
