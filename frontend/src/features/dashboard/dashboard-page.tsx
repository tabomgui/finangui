import { Landmark, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useBankConnections } from '@/api/queries/bank-connections'
import { useMe } from '@/api/queries/auth'
import { useDashboard } from '@/api/queries/dashboard'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { monthKey } from '@/lib/date'
import { cn } from '@/lib/utils'
import { ReauthBanner } from '../banking/reauth-banner'
import { useReconnectFlow } from '../banking/use-reconnect-flow'
import { AccountsCard } from './accounts-card'
import { BalanceHero } from './balance-hero'
import { MonthNav } from './month-nav'
import { OverdueOccurrencesDialog } from './overdue-occurrences-dialog'
import { PendingCard } from './pending-card'
import { RecentTransactionsCard } from './recent-transactions-card'
import { monthFromParam } from './shares'
import { StatementsCard } from './statements-card'
import { SummaryCards } from './summary-cards'
import { TopCategoriesCard } from './top-categories-card'

export function DashboardPage() {
  const [params, setParams] = useSearchParams()
  // `new Date()` só na montagem: evita recalcular "hoje" a cada render.
  const [currentMonth] = useState(() => monthKey(new Date()))
  const month = monthFromParam(params.get('mes'), currentMonth)
  const { data, isPending, isError, isPlaceholderData, refetch } = useDashboard(month)
  const { data: connections } = useBankConnections()
  const { data: me } = useMe()
  const reconnectFlow = useReconnectFlow()
  // Dono aqui, não dentro de `PendingCard`: resolver a última pendência zera a contagem e o card
  // pode desmontar (ex.: sem sugestão também) — o diálogo aberto não pode ir junto nesse momento.
  const [overdueOpen, setOverdueOpen] = useState(false)

  const setMonth = (next: string) => setParams({ mes: next }, { replace: true })

  return (
    <>
      <PageHeader title="Início">
        <div className="space-y-4">
          <MonthNav month={month} onChange={setMonth} />
          {data ? (
            <div className={cn('transition-opacity', isPlaceholderData && 'opacity-60')}>
              <BalanceHero
                totalBalance={data.total_balance}
                currency={data.currency}
                balanceDate={data.balance_date}
                projectedBalance={data.projected_balance}
                month={month}
                currentMonth={currentMonth}
                isPlaceholderData={isPlaceholderData}
              />
            </div>
          ) : isError ? (
            // Sem saldo para mostrar e o card de erro já aparece no corpo da página: só reserva a altura
            // para o cabeçalho não "pular" quando o usuário tentar de novo.
            <div className="h-28" aria-hidden="true" />
          ) : (
            <Skeleton className="h-28 w-full rounded-2xl bg-white/20" />
          )}
        </div>
      </PageHeader>
      <PageBody>
        <ReauthBanner
          connections={connections ?? []}
          onReconnect={reconnectFlow.reconnect}
          reconnectDisabled={reconnectFlow.isPending}
          bankingEnabled={me?.banking_enabled}
        />
        {isError ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar o resumo do mês."
              action={
                <Button variant="outline" onClick={() => refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          </Card>
        ) : isPending || !data ? (
          <div className="grid gap-3 sm:grid-cols-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 rounded-2xl" />
            ))}
          </div>
        ) : data.accounts.length === 0 ? (
          <Card className="rounded-2xl shadow-card">
            <EmptyState
              icon={Landmark}
              title="Comece cadastrando uma conta"
              description="Com uma conta você já pode registrar receitas, despesas e transferências."
              action={
                <Button asChild>
                  <Link to="/contas">Cadastrar conta</Link>
                </Button>
              }
            />
          </Card>
        ) : (
          <div className={cn('space-y-4 transition-opacity', isPlaceholderData && 'opacity-60')} aria-busy={isPlaceholderData}>
            <SummaryCards income={data.income} expense={data.expense} net={data.net} currency={data.currency} />
            <PendingCard onOpenOverdue={() => setOverdueOpen(true)} />
            <StatementsCard />
            <div className="grid gap-4 lg:grid-cols-2">
              <TopCategoriesCard categories={data.top_categories} expense={data.expense} currency={data.currency} month={month} />
              <AccountsCard accounts={data.accounts} />
            </div>
            <RecentTransactionsCard />
          </div>
        )}
      </PageBody>

      <OverdueOccurrencesDialog open={overdueOpen} onOpenChange={setOverdueOpen} />
      {reconnectFlow.widget}
    </>
  )
}
