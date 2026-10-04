import { EllipsisVertical, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'
import type { BudgetItem } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
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
import { cn } from '@/lib/utils'
import { BudgetDeleteDialog } from './budget-delete-dialog'

type BudgetRowProps = { item: BudgetItem; month: string; currency: string; onEdit: () => void }

export function BudgetRow({ item, month, currency, onEdit }: BudgetRowProps) {
  const [deleting, setDeleting] = useState(false)
  // Não usa `item.percent > 100`: ele é inteiro (arredondado) e pode ficar em 100 mesmo já
  // tendo passado um pouco do orçamento — `remaining` é a fonte exata (`amount - spent`).
  const over = item.remaining < 0
  // A largura da barra satura em 100%; o número ao lado continua mostrando o percentual real.
  const barWidth = Math.min(item.percent, 100)

  return (
    <div className="space-y-2 px-3 py-3">
      <div className="flex items-center gap-3">
        <CategoryIcon icon={item.category.icon} color={item.category.color} size="sm" />
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-2 font-medium">
            <span className="truncate">{item.category.name}</span>
            {item.source === 'override' && (
              <Badge variant="secondary" className="shrink-0">
                Só este mês
              </Badge>
            )}
          </p>
          <p className="text-xs text-muted-foreground">
            <MoneyText cents={item.spent} currency={currency} colored={false} /> de{' '}
            <MoneyText cents={item.amount} currency={currency} colored={false} />
          </p>
        </div>
        <div className="text-right">
          <p className={cn('font-semibold tabular-nums', over && 'text-expense')}>{item.percent}%</p>
          <p className="text-xs text-muted-foreground">
            {item.remaining < 0 ? 'excedeu ' : 'restam '}
            <MoneyText cents={Math.abs(item.remaining)} currency={currency} colored={false} />
          </p>
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" aria-label={`Ações do orçamento de ${item.category.name}`}>
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
        aria-label={`Progresso do orçamento de ${item.category.name}`}
        aria-valuenow={item.percent}
        aria-valuemin={0}
        aria-valuemax={100}
        className="h-2 overflow-hidden rounded-full bg-muted"
      >
        <div className={cn('h-full rounded-full', over ? 'bg-expense' : 'bg-primary')} style={{ width: `${barWidth}%` }} />
      </div>

      <BudgetDeleteDialog open={deleting} onOpenChange={setDeleting} month={month} item={item} />
    </div>
  )
}
