import type { CategoryKind, Direction } from '@/api/types'

/** Forma mínima que as funções de seleção precisam — qualquer grupo/lançamento real serve. */
export type SelectableTransaction = { id: number; direction: Direction }
export type SelectableGroup = { key: string; transactions: SelectableTransaction[] }

export type GroupCheckState = 'none' | 'partial' | 'all'

/** Estado do checkbox do cabeçalho do grupo: nenhum, parcial (alguns marcados) ou todos. */
export function groupCheckState(group: SelectableGroup, selectedIds: ReadonlySet<number>): GroupCheckState {
  if (group.transactions.length === 0) return 'none'
  const selectedCount = group.transactions.filter((transaction) => selectedIds.has(transaction.id)).length
  if (selectedCount === 0) return 'none'
  if (selectedCount === group.transactions.length) return 'all'
  return 'partial'
}

/**
 * Marca/desmarca o grupo inteiro (inclusive lançamentos além dos visíveis na tela, já que o
 * grupo completo sempre está carregado): se já está todo marcado, desmarca; senão, marca todos.
 */
export function toggleGroupSelection(selectedIds: ReadonlySet<number>, group: SelectableGroup): Set<number> {
  const next = new Set(selectedIds)
  const allSelected = group.transactions.length > 0 && group.transactions.every((transaction) => next.has(transaction.id))
  for (const transaction of group.transactions) {
    if (allSelected) next.delete(transaction.id)
    else next.add(transaction.id)
  }
  return next
}

/** "Só saídas"/"só entradas": substitui a seleção daquele grupo pelos lançamentos da direção escolhida. */
export function selectGroupDirection(selectedIds: ReadonlySet<number>, group: SelectableGroup, direction: Direction): Set<number> {
  const next = new Set(selectedIds)
  for (const transaction of group.transactions) next.delete(transaction.id)
  for (const transaction of group.transactions) {
    if (transaction.direction === direction) next.add(transaction.id)
  }
  return next
}

/**
 * Tipo de categoria compatível com a seleção atual: 'income'/'expense' se todos os lançamentos
 * selecionados forem da mesma direção, 'mixed' se misturar as duas, `null` se nada selecionado.
 */
export function selectionKind(transactions: SelectableTransaction[]): CategoryKind | 'mixed' | null {
  if (transactions.length === 0) return null
  const directions = new Set(transactions.map((transaction) => transaction.direction))
  if (directions.size > 1) return 'mixed'
  return directions.has('out') ? 'expense' : 'income'
}

/** Verdadeiro se algum grupo tem seleção parcial (só parte dos seus lançamentos marcados). */
export function hasPartialGroupSelection(groups: SelectableGroup[], selectedIds: ReadonlySet<number>): boolean {
  return groups.some((group) => groupCheckState(group, selectedIds) === 'partial')
}
