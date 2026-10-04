import { LoaderCircle } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { useDeleteBudget, useSaveBudget } from '@/api/queries/budgets'
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
import { monthName } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { BudgetScopeToggle, type BudgetScope } from './budget-scope-toggle'

type BudgetDeleteDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  month: string
  item: BudgetItem
}

function defaultScopeFor(item: BudgetItem): BudgetScope {
  return item.source === 'override' ? 'month' : 'default'
}

export function BudgetDeleteDialog({ open, onOpenChange, month, item }: BudgetDeleteDialogProps) {
  const [scope, setScope] = useState<BudgetScope>(() => defaultScopeFor(item))
  const remove = useDeleteBudget()
  // Quando a categoria não tem exceção do mês (`source: 'default'`), não existe nada pra apagar
  // com `month`: o escopo "mês" vira zerar o valor só deste mês (override de R$ 0), que é um
  // PUT, não um DELETE (ver `handleOpenChange`/`confirm`).
  const save = useSaveBudget()
  const pending = remove.isPending || save.isPending

  // Reseta ao abrir, inclusive trocando de item (categoria): mesma convenção de formulário em
  // diálogo (ver `budget-form-dialog.tsx`).
  useEffect(() => {
    if (open) setScope(defaultScopeFor(item))
  }, [open, item])

  function handleOpenChange(next: boolean) {
    if (pending) return
    onOpenChange(next)
  }

  async function confirm() {
    try {
      if (scope === 'month' && item.source === 'default') {
        await save.mutateAsync({ category_id: item.category.id, amount: 0, month })
      } else {
        await remove.mutateAsync({ category_id: item.category.id, ...(scope === 'month' ? { month } : {}) })
      }
      toast.success('Orçamento removido.')
      onOpenChange(false)
    } catch (error) {
      notifyError(error)
    }
  }

  const monthLabel = item.source === 'default' ? `Sem orçamento só em ${monthName(month)}` : undefined

  return (
    <AlertDialog open={open} onOpenChange={handleOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Remover orçamento de {item.category.name}?</AlertDialogTitle>
          <AlertDialogDescription>Escolha se isso vale para todos os meses ou só para este.</AlertDialogDescription>
        </AlertDialogHeader>
        <BudgetScopeToggle value={scope} onChange={setScope} month={month} disabled={pending} monthLabel={monthLabel} />
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>Cancelar</AlertDialogCancel>
          <Button variant="destructive" disabled={pending} onClick={confirm}>
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Remover
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
