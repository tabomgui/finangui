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

/** "1 transação" / "3 transações", para montar frases sem "(s)". */
export function transacaoCount(count: number): string {
  return `${count} ${count === 1 ? 'transação' : 'transações'}`
}
