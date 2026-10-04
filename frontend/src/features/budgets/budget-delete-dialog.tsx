import { LoaderCircle } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useDeleteBudget } from '@/api/queries/budgets'
import type { BudgetItem } from '@/api/types'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { notifyError } from '@/lib/form-errors'
import { BudgetScopeToggle, type BudgetScope } from './budget-scope-toggle'

type BudgetDeleteDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  month: string
  item: BudgetItem
}

export function BudgetDeleteDialog({ open, onOpenChange, month, item }: BudgetDeleteDialogProps) {
  // Chute inicial: remover a exceção do mês quando o valor mostrado vem dela ("override"), ou
  // o padrão quando não há exceção — o usuário ainda pode trocar antes de confirmar.
  const [scope, setScope] = useState<BudgetScope>(item.source === 'override' ? 'month' : 'default')
  const remove = useDeleteBudget()

  function handleOpenChange(next: boolean) {
    if (remove.isPending) return
    if (next) setScope(item.source === 'override' ? 'month' : 'default')
    onOpenChange(next)
  }

  async function confirm() {
    try {
      await remove.mutateAsync({ category_id: item.category.id, ...(scope === 'month' ? { month } : {}) })
      toast.success('Orçamento removido.')
      onOpenChange(false)
    } catch (error) {
      notifyError(error)
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={handleOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Remover orçamento de {item.category.name}?</AlertDialogTitle>
          <AlertDialogDescription>Escolha se isso vale para todos os meses ou só para este.</AlertDialogDescription>
        </AlertDialogHeader>
        <BudgetScopeToggle value={scope} onChange={setScope} month={month} disabled={remove.isPending} />
        <AlertDialogFooter>
          <AlertDialogCancel disabled={remove.isPending}>Cancelar</AlertDialogCancel>
          <Button variant="destructive" disabled={remove.isPending} onClick={confirm}>
            {remove.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Remover
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
