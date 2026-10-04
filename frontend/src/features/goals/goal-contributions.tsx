import { Plus, Trash2, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useDeleteGoalContribution, useGoalContributions } from '@/api/queries/goals'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/date'
import { ContributionFormDialog } from './contribution-form-dialog'

type GoalContributionsProps = { goalId: number; currency: string }

export function GoalContributions({ goalId, currency }: GoalContributionsProps) {
  const { data, isPending, isError, refetch } = useGoalContributions(goalId)
  const [formOpen, setFormOpen] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const remove = useDeleteGoalContribution(goalId)

  return (
    <div className="space-y-3 border-t border-border pt-3">
      {isError ? (
        <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
          <span className="flex items-center gap-2">
            <TriangleAlert className="h-4 w-4" />
            Não foi possível carregar os aportes.
          </span>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Tentar de novo
          </Button>
        </div>
      ) : isPending || !data ? (
        <Skeleton className="h-10 w-full rounded-lg" />
      ) : data.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nenhum aporte registrado ainda.</p>
      ) : (
        <ul className="space-y-1">
          {data.map((contribution) => (
            <li key={contribution.id} className="flex items-center justify-between gap-2 text-sm">
              <p className="min-w-0 truncate text-muted-foreground">
                {formatDate(contribution.date)}
                {contribution.note && ` · ${contribution.note}`}
              </p>
              <div className="flex shrink-0 items-center gap-1">
                <MoneyText cents={contribution.amount} currency={currency} />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={`Remover lançamento de ${formatDate(contribution.date)}`}
                  onClick={() => setDeletingId(contribution.id)}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <Button type="button" variant="outline" size="sm" onClick={() => setFormOpen(true)}>
        <Plus className="h-4 w-4" />
        Novo aporte ou retirada
      </Button>

      <ContributionFormDialog open={formOpen} onOpenChange={setFormOpen} goalId={goalId} />

      <ConfirmDialog
        open={deletingId !== null}
        onOpenChange={(next) => !next && setDeletingId(null)}
        title="Remover este lançamento?"
        destructive
        confirmLabel="Remover"
        onConfirm={async () => {
          if (deletingId === null) return
          await remove.mutateAsync(deletingId)
          toast.success('Lançamento removido.')
        }}
      />
    </div>
  )
}
