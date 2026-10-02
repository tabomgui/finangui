import type { QueryClient } from '@tanstack/react-query'
import type { Direction } from '@/api/types'

export type TransactionFilters = {
  account_id?: number
  category_id?: number
  tag_id?: number
  from?: string
  to?: string
  direction?: Direction
  search?: string
}

/** Remove chaves vazias para que filtros equivalentes gerem a mesma chave de cache. */
export function compactFilters(filters: TransactionFilters): TransactionFilters {
  return Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  ) as TransactionFilters
}

export const queryKeys = {
  accounts: (includeArchived: boolean) => ['accounts', { includeArchived }] as const,
  categories: (includeArchived: boolean) => ['categories', { includeArchived }] as const,
  tags: () => ['tags'] as const,
  transactions: (filters: TransactionFilters) => ['transactions', 'list', compactFilters(filters)] as const,
  recentTransactions: () => ['transactions', 'recent'] as const,
  transaction: (id: number) => ['transactions', 'detail', id] as const,
  transfersRoot: () => ['transfers'] as const,
  transfer: (id: string) => ['transfers', id] as const,
  dashboard: (month: string) => ['dashboard', month] as const,
}

function invalidate(queryClient: QueryClient, roots: string[]) {
  return Promise.all(roots.map((root) => queryClient.invalidateQueries({ queryKey: [root] })))
}

/** Transações, transferências e contas mexem em saldos, listas e no resumo do mês. */
export function invalidateLedger(queryClient: QueryClient) {
  return invalidate(queryClient, ['transactions', 'transfers', 'accounts', 'dashboard'])
}

/** Nome, ícone e flag de transferência aparecem nas transações e nas top categorias do mês. */
export function invalidateCategories(queryClient: QueryClient) {
  return invalidate(queryClient, ['categories', 'transactions', 'dashboard'])
}

export function invalidateTags(queryClient: QueryClient) {
  return invalidate(queryClient, ['tags', 'transactions'])
}
