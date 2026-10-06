import type { TransactionFilters } from '@/api/query-keys'
import type { SpendingCategory, SpendingChild } from '@/api/types'

/**
 * Uma linha do nível atual do card de distribuição de gastos — categoria-raiz (ou "Sem
 * categoria") no nível de topo, ou subcategoria/"<Pai> (direto)" dentro de um detalhamento.
 * `key` identifica a linha dentro do nível atual (estável para seleção/destaque e para `key` de
 * listas React); não é o `category_id` puro porque duas linhas do mesmo nível podem compartilhar
 * o id (a entrada "direto no pai" usa o id do próprio pai).
 */
export type SpendingViewEntry = {
  key: string
  categoryId: number | null
  name: string
  color: string | null
  icon: string | null
  amount: number
  count: number
  direct: boolean
  hasChildren: boolean
  percent: number
}

function withPercent<T extends { amount: number }>(entries: T[], total: number): (T & { percent: number })[] {
  return entries.map((entry) => ({ ...entry, percent: total > 0 ? Math.round((entry.amount / total) * 100) : 0 }))
}

/** Nível de topo: uma linha por categoria-raiz, mais "Sem categoria" (`categoryId` nulo). */
export function rootViewEntries(categories: SpendingCategory[], total: number): SpendingViewEntry[] {
  return withPercent(
    categories.map((category) => ({
      key: String(category.category_id ?? 'none'),
      categoryId: category.category_id ?? null,
      name: category.name,
      color: category.color,
      icon: category.icon,
      amount: category.amount,
      count: category.count,
      direct: false,
      hasChildren: category.children.length > 0,
    })),
    total,
  )
}

/** Detalhamento: uma linha por subcategoria com gasto, mais a entrada "direto no pai" quando houver. */
export function childViewEntries(children: SpendingChild[], total: number): SpendingViewEntry[] {
  return withPercent(
    children.map((child) => {
      // `direct` só vem quando true (ver convenção de nunca tipar uma chave só como `null`/ausente no CLAUDE.md).
      const direct = child.direct ?? false
      return {
        key: direct ? `${child.category_id}-direct` : String(child.category_id),
        categoryId: child.category_id,
        name: child.name,
        color: child.color,
        icon: child.icon,
        amount: child.amount,
        count: child.count,
        direct,
        hasChildren: false,
      }
    }),
    total,
  )
}

/** "<Pai> (direto)" para a entrada de gasto lançado direto na categoria-pai; senão, o nome como veio da API. */
export function entryLabel(entry: { name: string; direct: boolean }): string {
  return entry.direct ? `${entry.name} (direto)` : entry.name
}

/**
 * Filtros de `/transactions` equivalentes a uma linha do gráfico, usados tanto para a prévia de
 * lançamentos sob demanda quanto para o link "Ver todos" (mesmos dois usos, mesmos filtros —
 * nunca construídos em separado, para não desalinhar um do outro). No nível de topo, categoria
 * sem `category_exact` já inclui as subcategorias; no detalhamento, sempre exata (subcategoria
 * ou "direto no pai"), nunca misturando as duas.
 */
export function entryTransactionFilters(
  entry: SpendingViewEntry,
  context: { from: string; to: string; currency: string; isChildLevel: boolean },
): TransactionFilters {
  const base: TransactionFilters = {
    reportable: true,
    currency: context.currency,
    direction: 'out',
    from: context.from,
    to: context.to,
  }

  if (entry.categoryId === null) return { ...base, no_category: true }

  return context.isChildLevel
    ? { ...base, category_id: entry.categoryId, category_exact: true }
    : { ...base, category_id: entry.categoryId }
}
