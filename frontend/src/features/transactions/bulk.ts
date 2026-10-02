import type { CategoryKind, Direction } from '@/api/types'

export function mergeTagIds(current: number[], add: number): number[] {
  return current.includes(add) ? current : [...current, add]
}

/**
 * Tipo de categoria compatível com a seleção: 'expense' se todas as transações forem saídas,
 * 'income' se todas forem entradas, `null` se a seleção estiver vazia ou misturar os dois
 * (inclusive pernas de transferência, que contam pela própria `direction`).
 */
export function selectionKind(transactions: { direction: Direction }[]): CategoryKind | null {
  if (transactions.length === 0) return null
  const directions = new Set(transactions.map((transaction) => transaction.direction))
  if (directions.size > 1) return null
  return directions.has('out') ? 'expense' : 'income'
}

/** Roda `task` para cada id com no máximo `concurrency` em paralelo; não para na primeira falha. */
export async function runInBatches<T>(
  ids: T[],
  task: (id: T) => Promise<unknown>,
  concurrency = 4,
): Promise<{ succeeded: T[]; failed: T[] }> {
  const succeeded: T[] = []
  const failed: T[] = []
  let cursor = 0

  async function worker() {
    while (cursor < ids.length) {
      const id = ids[cursor]
      cursor += 1
      try {
        await task(id)
        succeeded.push(id)
      } catch {
        failed.push(id)
      }
    }
  }

  await Promise.all(Array.from({ length: Math.min(concurrency, ids.length) }, worker))

  const order = (list: T[]) => ids.filter((id) => list.includes(id))
  return { succeeded: order(succeeded), failed: order(failed) }
}

/** "1 transação" / "3 transações", para montar frases sem "(s)". */
export function transacaoCount(count: number): string {
  return `${count} ${count === 1 ? 'transação' : 'transações'}`
}
