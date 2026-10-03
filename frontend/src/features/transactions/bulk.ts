import type { CategoryKind, Direction, Transaction } from '@/api/types'

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

type LinkCandidate = Pick<Transaction, 'id' | 'direction' | 'amount' | 'account_id' | 'transfer_id'>

/**
 * Condições básicas e visíveis de "juntar como transferência" (exatamente 2 selecionadas,
 * direções opostas, mesmo valor, contas diferentes, nenhuma já é perna de transferência);
 * o backend valida de novo (moeda, janela de dias) ao receber o pedido.
 */
export function canLinkAsTransfer(selected: LinkCandidate[]): boolean {
  if (selected.length !== 2) return false
  const [a, b] = selected
  return (
    a.direction !== b.direction &&
    a.amount === b.amount &&
    a.account_id !== b.account_id &&
    a.transfer_id === null &&
    b.transfer_id === null
  )
}

/** A perna de saída e a de entrada entre as duas selecionadas, na ordem que `LinkTransferRequest` espera. */
export function orderForLink<T extends LinkCandidate>(selected: T[]): { out: T; in: T } {
  const [a, b] = selected
  return a.direction === 'out' ? { out: a, in: b } : { out: b, in: a }
}
