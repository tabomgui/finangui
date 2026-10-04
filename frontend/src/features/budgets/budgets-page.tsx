import { PiggyBank, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMonthBudget } from '@/api/queries/budgets'
import type { BudgetItem } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { monthKey } from '@/lib/date'
import { cn } from '@/lib/utils'
import { MonthNav } from '../dashboard/month-nav'
import { monthFromParam } from '../dashboard/shares'
import { BudgetFormDialog } from './budget-form-dialog'
import { BudgetRow } from './budget-row'
import { BudgetSummaryCards } from './budget-summary-cards'

export function BudgetsPage() {
  const [params, setParams] = useSearchParams()
  // `new Date()` só na montagem: evita recalcular "hoje" a cada render (ver dashboard-page.tsx).
  const [currentMonth] = useState(() => monthKey(new Date()))
  const month = monthFromParam(params.get('mes'), currentMonth)
  const { data, isPending, isError, isPlaceholderData, refetch } = useMonthBudget(month)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<BudgetItem | undefined>()

  const setMonth = (next: string) => setParams({ mes: next }, { replace: true })

  const openCreate = () => {
    setEditing(undefined)
    setFormOpen(true)
  }

  const openEdit = (item: BudgetItem) => {
    setEditing(item)
    setFormOpen(true)
  }

  return (
    <>
      <PageHeader
        title="Orçamento"
        actions={
          <Button className={headerButton} onClick={openCreate}>
            <PiggyBank className="h-4 w-4" />
            <span className="sr-only sm:not-sr-only">Orçar categoria</span>
          </Button>
        }
      >
        <MonthNav month={month} onChange={setMonth} />
      </PageHeader>
      <PageBody>
        {isError && !data ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar o orçamento do mês."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        ) : isPending || !data ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16 w-full rounded-xl" />
            ))}
          </div>
        ) : (
          <div className={cn('space-y-4 transition-opacity', isPlaceholderData && 'opacity-60')} aria-busy={isPlaceholderData}>
            <BudgetSummaryCards budgeted={data.totals.budgeted} spent={data.totals.spent} currency={data.currency} />

            {data.items.length === 0 ? (
              <Card className="rounded-2xl shadow-card">
                <EmptyState
                  icon={PiggyBank}
                  title="Nenhuma categoria orçada este mês"
                  description="Defina um limite mensal para acompanhar o quanto já gastou em cada categoria."
                  action={<Button onClick={openCreate}>Orçar categoria</Button>}
                />
              </Card>
            ) : (
              <Card className="rounded-2xl p-2 shadow-card">
                <ul className="divide-y divide-border">
                  {data.items.map((item) => (
                    <li key={item.category.id}>
                      <BudgetRow item={item} month={month} currency={data.currency} onEdit={() => openEdit(item)} />
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            <p className="px-1 text-sm text-muted-foreground">
              Fora do orçamento: <MoneyText cents={data.unbudgeted_spent} currency={data.currency} colored={false} />
            </p>
          </div>
        )}
      </PageBody>

      <BudgetFormDialog open={formOpen} onOpenChange={setFormOpen} month={month} item={editing} />
    </>
  )
}
