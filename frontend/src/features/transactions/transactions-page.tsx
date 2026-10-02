import { List, Plus } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTransactions } from '@/api/queries/transactions'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { LoadMore } from '@/components/shared/load-more'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { formatDayLabel } from '@/lib/date'
import { activeFilterCount, filtersFromParams } from './filters'
import { groupByDay } from './group-by-day'
import { TransactionFilters } from './transaction-filters'
import { TransactionRow } from './transaction-row'

export function TransactionsPage() {
  const [params] = useSearchParams()
  const filters = filtersFromParams(params)
  const query = useTransactions(filters)
  const transactions = query.data?.pages.flatMap((page) => page.data) ?? []
  const filtered = activeFilterCount(filters) > 0 || filters.search !== undefined

  return (
    <>
      <PageHeader
        title="Transações"
        actions={
          <Button asChild className={`${headerButton} hidden md:inline-flex`}>
            <Link to="/transacoes/nova">
              <Plus className="h-4 w-4" />
              Nova
            </Link>
          </Button>
        }
      />
      <PageBody>
        <TransactionFilters filters={filters} />
        <Card className={cn('overflow-hidden rounded-2xl p-0 shadow-card transition-opacity', query.isFetching && !query.isPending && 'opacity-60')}>
          {query.isPending ? (
            <div className="space-y-2 p-3">
              {[0, 1, 2, 3, 4].map((i) => (
                <Skeleton key={i} className="h-14 w-full rounded-xl" />
              ))}
            </div>
          ) : transactions.length === 0 ? (
            <EmptyState
              icon={List}
              title={filtered ? 'Nada encontrado' : 'Nenhuma transação ainda'}
              description={filtered ? 'Ajuste os filtros ou a busca.' : 'Registre seu primeiro lançamento.'}
              action={
                filtered ? undefined : (
                  <Button asChild>
                    <Link to="/transacoes/nova">Nova transação</Link>
                  </Button>
                )
              }
            />
          ) : (
            groupByDay(transactions).map((group) => (
              <section key={group.date} aria-label={formatDayLabel(group.date)}>
                <h2 className="sticky top-0 z-10 bg-muted/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground backdrop-blur">
                  {formatDayLabel(group.date)}
                </h2>
                <ul className="divide-y divide-border">
                  {group.items.map((transaction) => (
                    <li key={transaction.id}>
                      <TransactionRow transaction={transaction} />
                    </li>
                  ))}
                </ul>
              </section>
            ))
          )}
        </Card>
        <LoadMore hasMore={Boolean(query.hasNextPage)} loading={query.isFetchingNextPage} onLoadMore={() => query.fetchNextPage()} />
      </PageBody>
    </>
  )
}
