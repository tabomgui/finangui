import { TriangleAlert } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useRecentTransactions } from '@/api/queries/transactions'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { TransactionRow } from '@/features/transactions/transaction-row'

export function RecentTransactionsCard() {
  const { data: transactions, isPending, isError, refetch } = useRecentTransactions(5)

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Últimos lançamentos</CardTitle>
        <Link to="/transacoes" className="text-sm font-medium text-primary hover:underline">
          Ver todos
        </Link>
      </CardHeader>
      <CardContent className="px-0">
        {isError ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar os lançamentos."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        ) : isPending ? (
          <div className="space-y-2 px-6">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-12 w-full rounded-xl" />
            ))}
          </div>
        ) : transactions && transactions.length > 0 ? (
          <ul className="divide-y divide-border">
            {transactions.map((transaction) => (
              <li key={transaction.id}>
                <TransactionRow transaction={transaction} />
              </li>
            ))}
          </ul>
        ) : (
          <p className="px-6 text-sm text-muted-foreground">Nenhum lançamento ainda.</p>
        )}
      </CardContent>
    </Card>
  )
}
