import { EllipsisVertical, Pause, Pencil, Play, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useDeleteRecurrence, useUpdateRecurrence } from '@/api/queries/recurrences'
import type { Recurrence } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { formatDate } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { frequencyLabel } from './recurrence-labels'

type RecurrenceRowProps = {
  recurrence: Recurrence
  onEdit: () => void
}

export function RecurrenceRow({ recurrence, onEdit }: RecurrenceRowProps) {
  const update = useUpdateRecurrence()
  const remove = useDeleteRecurrence()
  const [deleting, setDeleting] = useState(false)

  async function toggleActive() {
    try {
      await update.mutateAsync({ id: recurrence.id, body: { is_active: !recurrence.is_active } })
      toast.success(recurrence.is_active ? 'Recorrência pausada.' : 'Recorrência reativada.')
    } catch (error) {
      notifyError(error)
    }
  }

  const subtitle = [frequencyLabel(recurrence), recurrence.account?.name, recurrence.category?.name].filter(Boolean).join(' · ')

  return (
    <div className="flex items-center gap-3 px-3 py-3">
      <CategoryIcon icon={recurrence.category?.icon} color={recurrence.category?.color} />
      <div className="min-w-0 flex-1">
        <p className="flex items-center gap-2 truncate font-medium">
          {recurrence.description}
          {!recurrence.is_active && <Badge variant="secondary">Pausada</Badge>}
        </p>
        <p className="truncate text-xs text-muted-foreground">{subtitle}</p>
      </div>
      <div className="text-right">
        <MoneyText cents={recurrence.amount} direction={recurrence.direction} className="font-semibold" />
        {recurrence.next_date && <p className="text-xs text-muted-foreground">{formatDate(recurrence.next_date)}</p>}
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={`Ações da recorrência ${recurrence.description}`}>
            <EllipsisVertical className="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={onEdit}>
            <Pencil className="h-4 w-4" />
            Editar
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={toggleActive}>
            {recurrence.is_active ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
            {recurrence.is_active ? 'Pausar' : 'Reativar'}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem className="text-destructive" onSelect={() => setDeleting(true)}>
            <Trash2 className="h-4 w-4" />
            Excluir
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <ConfirmDialog
        open={deleting}
        onOpenChange={setDeleting}
        title={`Excluir ${recurrence.description}?`}
        description="Os lançamentos previstos serão excluídos. Os já lançados ficam."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          await remove.mutateAsync(recurrence.id)
          toast.success('Recorrência excluída.')
        }}
      />
    </div>
  )
}
