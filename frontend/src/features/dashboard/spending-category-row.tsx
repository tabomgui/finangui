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
import { entryColor } from './spending-colors'
import { entryLabel, type SpendingViewEntry } from './spending-shares'

type SpendingCategoryRowProps = {
  entry: SpendingViewEntry
  currency: string
  filters: TransactionFilters
}

/**
 * Linha de "Gastos por categoria": expande sob demanda (até 20 lançamentos, via
 * `useTransactionsPreview`, só habilitada enquanto aberta) e sempre oferece "Ver todos" com os
 * mesmos filtros — nunca uma lista maior embutida aqui (ver `entryTransactionFilters`).
 */
export function SpendingCategoryRow({ entry, currency, filters }: SpendingCategoryRowProps) {
  const [open, setOpen] = useState(false)
  const contentId = useId()
  const preview = useTransactionsPreview(filters, open)
  const label = entryLabel(entry)
  const color = entryColor(entry.categoryId, entry.color)
  const linkTo = `/transacoes?${paramsForFilters(filters).toString()}`

  return (
    <li>
      <button
        type="button"
        className="flex w-full items-center gap-3 rounded-lg px-1 py-3 text-left hover:bg-muted/50"
        aria-expanded={open}
        aria-controls={contentId}
        onClick={() => setOpen((value) => !value)}
      >
        <CategoryIcon icon={entry.icon} color={color} size="sm" />
        <span className="min-w-0 flex-1 truncate font-medium">{label}</span>
        <span className="text-xs text-muted-foreground">{entry.count}</span>
        <span className="text-right">
          <span className="block font-semibold tabular-nums">{formatMoney(entry.amount, currency)}</span>
          <span className="block text-xs text-muted-foreground">{entry.percent}%</span>
        </span>
        <ChevronDown className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
      </button>
      {open && (
        <div id={contentId} className="space-y-2 pb-3 pl-1">
          {preview.isError ? (
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
