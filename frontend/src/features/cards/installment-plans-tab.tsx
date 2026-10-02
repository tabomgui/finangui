import { EllipsisVertical, ListOrdered, Pencil, TriangleAlert, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useCancelInstallmentPlan, useInstallmentPlans } from '@/api/queries/cards'
import type { InstallmentPlan } from '@/api/types'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { InstallmentPlanDialog } from './installment-plan-dialog'

function ProgressBar({ description, postedCount, installments }: { description: string; postedCount: number; installments: number }) {
  const percent = installments > 0 ? Math.min(Math.max(postedCount / installments, 0), 1) * 100 : 0
  return (
    <div
      role="progressbar"
      aria-label={`Parcelas lançadas de ${description}`}
      aria-valuemin={0}
      aria-valuemax={installments}
      aria-valuenow={postedCount}
      aria-valuetext={`${postedCount} de ${installments} lançadas`}
      className="h-1.5 overflow-hidden rounded-full bg-muted"
    >
      <span className="block h-full bg-primary" style={{ width: `${percent}%` }} />
    </div>
  )
}

function PlanRow({ plan, currency, onEdit, onCancel }: { plan: InstallmentPlan; currency: string; onEdit: () => void; onCancel: () => void }) {
  const cancellable = plan.projected_count > 0 && plan.cancelled_at === null

  return (
    <li className="space-y-2 p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1 space-y-0.5">
          <p className="flex flex-wrap items-center gap-2 font-medium">
            <span className="truncate">{plan.description}</span>
            {plan.cancelled_at !== null && <Badge variant="secondary">Cancelado</Badge>}
          </p>
          <p className="text-sm text-muted-foreground">
            {plan.installments}x de {formatMoney(plan.installment_amount, currency)}
          </p>
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" aria-label={`Ações do parcelamento ${plan.description}`}>
              <EllipsisVertical className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={onEdit}>
              <Pencil className="h-4 w-4" />
              Editar
            </DropdownMenuItem>
            {cancellable && (
              <DropdownMenuItem className="text-destructive" onSelect={onCancel}>
                <X className="h-4 w-4" />
                Cancelar parcelamento
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <ProgressBar description={plan.description} postedCount={plan.posted_count} installments={plan.installments} />

      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        <span>
          {plan.posted_count} de {plan.installments} lançadas
        </span>
        {plan.projected_count > 0 && <span>Faltam {formatMoney(plan.remaining_amount, currency)}</span>}
        {plan.next_date !== null && <span>Próxima em {formatDate(plan.next_date)}</span>}
      </div>
    </li>
  )
}

export function InstallmentPlansTab({ cardId, currency }: { cardId: number; currency: string }) {
  const { data: plans, isPending, isError, refetch } = useInstallmentPlans(cardId)
  const cancel = useCancelInstallmentPlan()
  // `selectedPlan` nunca volta a null: uma vez que algum parcelamento foi escolhido para editar,
  // o diálogo continua montado (só `editing` alterna aberto/fechado), para não perder a transição
  // de saída no meio do fechamento — mesmo padrão de `selected`/`editingDates` em card-detail-page.
  const [selectedPlan, setSelectedPlan] = useState<InstallmentPlan | null>(null)
  const [editing, setEditing] = useState<InstallmentPlan | null>(null)
  const [cancelling, setCancelling] = useState<InstallmentPlan | null>(null)

  if (isError) {
    return (
      <EmptyState
        icon={TriangleAlert}
        title="Não foi possível carregar os parcelamentos."
        action={
          <Button variant="outline" onClick={() => refetch()}>
            Tentar de novo
          </Button>
        }
      />
    )
  }

  return (
    <>
      <Card className="gap-0 overflow-clip rounded-2xl p-0 shadow-card">
        {isPending ? (
          <div className="space-y-2 p-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16 w-full rounded-xl" />
            ))}
          </div>
        ) : plans && plans.length > 0 ? (
          <ul className="divide-y divide-border">
            {plans.map((plan) => (
              <PlanRow
                key={plan.id}
                plan={plan}
                currency={currency}
                onEdit={() => {
                  setSelectedPlan(plan)
                  setEditing(plan)
                }}
                onCancel={() => setCancelling(plan)}
              />
            ))}
          </ul>
        ) : (
          <EmptyState icon={ListOrdered} title="Nenhum parcelamento neste cartão." />
        )}
      </Card>

      {selectedPlan && (
        <InstallmentPlanDialog
          open={editing !== null}
          onOpenChange={(open) => setEditing(open ? selectedPlan : null)}
          plan={selectedPlan}
        />
      )}
      <ConfirmDialog
        open={cancelling !== null}
        onOpenChange={(open) => !open && setCancelling(null)}
        title="Cancelar parcelamento?"
        description="As parcelas futuras serão excluídas. As já lançadas ficam."
        confirmLabel="Cancelar parcelamento"
        cancelLabel="Voltar"
        destructive
        onConfirm={async () => {
          if (!cancelling) return
          await cancel.mutateAsync(cancelling.id)
          toast.success('Parcelamento cancelado.')
        }}
      />
    </>
  )
}
