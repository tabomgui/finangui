import { ChevronDown, TriangleAlert } from 'lucide-react'
import { useId, useState } from 'react'
import { Link } from 'react-router-dom'
import type { TransactionFilters } from '@/api/query-keys'
import { useTransactionsPreview } from '@/api/queries/transactions'
import { CategoryIcon } from '@/components/shared/category-icon'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { paramsForFilters } from '@/features/transactions/filters'
import { TransactionRow } from '@/features/transactions/transaction-row'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'
import { childViewEntries, entryLabel, entryTransactionFilters, transactionCountLabel, type SpendingViewEntry } from './spending-shares'

type SpendingCategoryRowProps = {
  entry: SpendingViewEntry
  currency: string
  filters: TransactionFilters
  from: string
  to: string
}

/**
 * Linha de "Gastos por categoria". Com subcategoria (`entry.hasChildren`), expande para o
 * detalhamento inline (uma `SpendingCategoryRow` recursiva por subcategoria, mesmo
 * `childViewEntries`/`entryTransactionFilters` do drill-down do donut/legenda em
 * `spending-card.tsx`) em vez da prévia da categoria-raiz inteira; "Ver todos" continua com os
 * filtros da própria entrada (categoria + subcategorias). Sem subcategoria (ou já no
 * detalhamento), expande sob demanda para até 20 lançamentos (via `useTransactionsPreview`, só
 * habilitada enquanto aberta) — nunca uma lista maior embutida aqui (ver `entryTransactionFilters`).
 */
export function SpendingCategoryRow({ entry, currency, filters, from, to }: SpendingCategoryRowProps) {
  const [open, setOpen] = useState(false)
  const contentId = useId()
  const preview = useTransactionsPreview(filters, open && !entry.hasChildren)
  const label = entryLabel(entry)
  const linkTo = `/transacoes?${paramsForFilters(filters).toString()}`
  const childEntries = entry.hasChildren ? childViewEntries(entry.children, entry.amount) : []

  return (
    <li>
      <button
        type="button"
        className="flex w-full items-center gap-3 rounded-lg px-1 py-3 text-left hover:bg-muted/50"
        aria-expanded={open}
        aria-controls={contentId}
        onClick={() => setOpen((value) => !value)}
      >
        <CategoryIcon icon={entry.icon} color={entry.displayColor} size="sm" />
        <span className="min-w-0 flex-1 font-medium break-words line-clamp-2">{label}</span>
        <span className="shrink-0 text-xs text-muted-foreground">
          {/* Nome tem prioridade no celular: aqui só o número, "N lançamentos" completo volta a partir de `sm`. */}
          <span className="sm:hidden">{entry.count}</span>
          <span className="hidden sm:inline">{transactionCountLabel(entry.count)}</span>
        </span>
        <span className="shrink-0 text-right">
          <span className="block font-semibold tabular-nums">{formatMoney(entry.amount, currency)}</span>
          <span className="block text-xs text-muted-foreground">{entry.percentLabel}</span>
        </span>
        <ChevronDown className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
      </button>
      {open && (
        <div id={contentId} className="space-y-2 pb-3 pl-1">
          {entry.hasChildren ? (
            <ul className="divide-y divide-border rounded-xl border">
              {childEntries.map((childEntry) => (
                <SpendingCategoryRow
                  key={childEntry.key}
                  entry={childEntry}
                  currency={currency}
                  filters={entryTransactionFilters(childEntry, { from, to, currency, isChildLevel: true })}
                  from={from}
                  to={to}
                />
              ))}
            </ul>
          ) : preview.isError ? (
            <div className="flex items-center justify-between gap-2 rounded-xl bg-muted px-3 py-2 text-sm">
              <span className="flex items-center gap-2 text-muted-foreground">
                <TriangleAlert className="h-4 w-4" />
                Não foi possível carregar os lançamentos.
              </span>
              <Button variant="outline" size="sm" onClick={() => preview.refetch()}>
                Tentar de novo
              </Button>
            </div>
          ) : preview.isPending ? (
            <div className="space-y-2">
              {[0, 1].map((i) => (
                <Skeleton key={i} className="h-12 w-full rounded-xl" />
              ))}
            </div>
          ) : preview.data.data.length === 0 ? (
            <p className="px-1 text-sm text-muted-foreground">Nenhum lançamento encontrado.</p>
          ) : (
            <ul className="divide-y divide-border rounded-xl border">
              {preview.data.data.map((transaction) => (
                <li key={transaction.id}>
                  <TransactionRow transaction={transaction} />
                </li>
              ))}
            </ul>
          )}
          <Link to={linkTo} className="inline-block text-sm font-medium text-primary hover:underline">
            Ver todos
          </Link>
        </div>
      )}
    </li>
  )
}
