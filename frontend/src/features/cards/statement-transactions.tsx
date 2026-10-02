import { List, TriangleAlert } from 'lucide-react'
import { useTransactions } from '@/api/queries/transactions'
import { EmptyState } from '@/components/shared/empty-state'
import { LoadMore } from '@/components/shared/load-more'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDayLabel } from '@/lib/date'
import { cn } from '@/lib/utils'
import { groupByDay } from '../transactions/group-by-day'
import { TransactionRow } from '../transactions/transaction-row'

export function StatementTransactions({ statementId }: { statementId: number }) {
  const query = useTransactions({ statement_id: statementId })
  const transactions = query.data?.pages.flatMap((page) => page.data) ?? []

  if (query.isError) {
    return (
      <Card className="rounded-2xl p-0 shadow-card">
        <EmptyState
          icon={TriangleAlert}
          title="Não foi possível carregar os lançamentos."
          action={
            <Button variant="outline" onClick={() => query.refetch()}>
              Tentar de novo
            </Button>
          }
        />
      </Card>
    )
  }

  return (
    <>
      <Card
        aria-busy={query.isPlaceholderData}
        className={cn('gap-0 overflow-clip rounded-2xl p-0 shadow-card transition-opacity', query.isPlaceholderData && 'opacity-60')}
      >
        {query.isPending ? (
          <div className="space-y-2 p-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-14 w-full rounded-xl" />
            ))}
          </div>
        ) : transactions.length === 0 ? (
          <EmptyState icon={List} title="Nenhum lançamento nesta fatura." />
        ) : (
          groupByDay(transactions).map((group) => (
            <section key={group.date} aria-label={formatDayLabel(group.date)}>
              <h2 className="sticky top-[env(safe-area-inset-top)] z-10 bg-muted/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground backdrop-blur">
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
      <LoadMore
        hasMore={Boolean(query.hasNextPage) && !query.isPlaceholderData}
        loading={query.isFetchingNextPage}
        onLoadMore={() => query.fetchNextPage()}
      />
    </>
  )
}
