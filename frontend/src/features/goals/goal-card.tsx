import { ChevronDown, ChevronUp, EllipsisVertical, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useDeleteGoal } from '@/api/queries/goals'
import type { Goal } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { formatDate } from '@/lib/date'
import { cn } from '@/lib/utils'
import { GoalContributions } from './goal-contributions'

type GoalCardProps = { goal: Goal; currency: string; onEdit: () => void }

export function GoalCard({ goal, currency, onEdit }: GoalCardProps) {
  const [expanded, setExpanded] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const remove = useDeleteGoal()

  const achieved = goal.achieved_at !== null
  const barWidth = Math.min(goal.percent, 100)
  const hasAccount = goal.account_id !== null

  return (
    <Card className="rounded-2xl shadow-card">
      <CardContent className="space-y-3 p-4">
        <div className="flex items-center gap-3">
          <CategoryIcon icon={goal.icon} color={goal.color} />
          <div className="min-w-0 flex-1">
            <p className="flex items-center gap-2 font-medium">
              <span className="truncate">{goal.name}</span>
              {achieved && <Badge className="shrink-0 bg-income text-white">Atingida</Badge>}
            </p>
            {goal.account && <p className="truncate text-xs text-muted-foreground">Saldo de {goal.account.name}</p>}
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" aria-label={`Ações da meta ${goal.name}`}>
                <EllipsisVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={onEdit}>
                <Pencil className="h-4 w-4" />
                Editar
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem className="text-destructive" onSelect={() => setDeleting(true)}>
                <Trash2 className="h-4 w-4" />
                Remover
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        <div
          role="progressbar"
          aria-label={`Progresso de ${goal.name}`}
          aria-valuenow={goal.percent}
          aria-valuemin={0}
          aria-valuemax={100}
          className="h-2 overflow-hidden rounded-full bg-muted"
        >
          <div className={cn('h-full rounded-full', achieved ? 'bg-income' : 'bg-primary')} style={{ width: `${barWidth}%` }} />
        </div>

        <p className="text-sm text-muted-foreground">
          <MoneyText cents={goal.progress} currency={currency} colored={false} /> de{' '}
          <MoneyText cents={goal.target_amount} currency={currency} colored={false} />
        </p>

        {!achieved && (
          <p className="text-sm">
            Faltam <MoneyText cents={goal.remaining} currency={currency} colored={false} />
          </p>
        )}

        {!achieved && goal.target_date && goal.monthly_needed !== undefined && (
          <p className="text-xs text-muted-foreground">
            Guarde <MoneyText cents={goal.monthly_needed} currency={currency} colored={false} /> por mês até{' '}
            {formatDate(goal.target_date)}
          </p>
        )}

        {!hasAccount && (
          <div className="space-y-3">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              aria-expanded={expanded}
              aria-controls={`goal-contributions-${goal.id}`}
              className="gap-1 px-0 text-muted-foreground hover:bg-transparent"
              onClick={() => setExpanded((current) => !current)}
            >
              {expanded ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
              Aportes
            </Button>
            {expanded && (
              <div id={`goal-contributions-${goal.id}`}>
                <GoalContributions goalId={goal.id} currency={currency} />
              </div>
            )}
          </div>
        )}
      </CardContent>

      <ConfirmDialog
        open={deleting}
        onOpenChange={setDeleting}
        title={`Remover a meta "${goal.name}"?`}
        description="Os aportes registrados também são removidos."
        destructive
        confirmLabel="Remover"
        onConfirm={async () => {
          await remove.mutateAsync(goal.id)
          toast.success('Meta removida.')
        }}
      />
    </Card>
  )
}
