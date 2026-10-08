import { Target, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useMe } from '@/api/queries/auth'
import { useGoals } from '@/api/queries/goals'
import type { Goal } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { GoalCard } from './goal-card'
import { GoalFormDialog } from './goal-form-dialog'

export function GoalsPage() {
  const { data: me } = useMe()
  const { data: goals, isPending, isError, refetch } = useGoals()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Goal | undefined>()
  const currency = me?.primary_currency ?? 'BRL'

  const openCreate = () => {
    setEditing(undefined)
    setFormOpen(true)
  }

  const openEdit = (goal: Goal) => {
    setEditing(goal)
    setFormOpen(true)
  }

  return (
    <>
      <PageHeader
        title="Metas"
        subtitle="Objetivos, progresso e aportes"
        actions={
          <Button className={headerButton} onClick={openCreate}>
            <Target className="h-4 w-4" aria-hidden="true" />
            <span className="sr-only sm:not-sr-only">Nova meta</span>
          </Button>
        }
      />
      <PageBody>
        {isError && !goals ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar as metas."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        ) : isPending || !goals ? (
          <div className="space-y-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-40 w-full rounded-2xl" />
            ))}
          </div>
        ) : goals.length === 0 ? (
          <Card className="rounded-2xl shadow-card">
            <EmptyState
              icon={Target}
              title="Nenhuma meta ainda"
              description="Crie uma meta para acompanhar quanto já guardou ou o saldo de uma conta."
              action={<Button onClick={openCreate}>Nova meta</Button>}
            />
          </Card>
        ) : (
          <div className="space-y-3">
            {goals.map((goal) => (
              <GoalCard key={goal.id} goal={goal} currency={currency} onEdit={() => openEdit(goal)} />
            ))}
          </div>
        )}
      </PageBody>

      <GoalFormDialog open={formOpen} onOpenChange={setFormOpen} goal={editing} />
    </>
  )
}
