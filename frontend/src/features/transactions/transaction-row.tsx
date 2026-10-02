import { ArrowLeftRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { Transaction } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'

type TransactionRowProps = {
  transaction: Transaction
  selectable?: boolean
  selected?: boolean
  onToggle?: (id: number) => void
}

export function TransactionRow({ transaction, selectable = false, selected = false, onToggle }: TransactionRowProps) {
  const isTransfer = transaction.transfer_id !== null
  const subtitle = [isTransfer ? 'Transferência' : (transaction.category?.name ?? 'Sem categoria'), transaction.account?.name]
    .filter(Boolean)
    .join(' · ')

  const content = (
    <>
      {isTransfer ? (
        <span aria-hidden className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <ArrowLeftRight className="h-5 w-5" />
        </span>
      ) : (
        <CategoryIcon icon={transaction.category?.icon} color={transaction.category?.color} />
      )}
      <div className="min-w-0 flex-1">
        <p className={cn('truncate font-medium', transaction.is_ignored && 'text-muted-foreground line-through')}>
          {transaction.description}
        </p>
        <p className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
          <span className="min-w-0 truncate">{subtitle}</span>
          {transaction.status === 'pending' && <Badge variant="outline">Pendente</Badge>}
          {transaction.status === 'projected' && <Badge variant="outline">Prevista</Badge>}
          {transaction.is_ignored && <Badge variant="secondary">Ignorada</Badge>}
          {(transaction.tags ?? []).map((tag) => (
            <Badge key={tag.id} variant="secondary">
              #{tag.name}
            </Badge>
          ))}
        </p>
      </div>
      <MoneyText
        cents={transaction.amount}
        currency={transaction.currency}
        direction={transaction.direction}
        colored={!isTransfer && !transaction.is_ignored}
        className={cn('shrink-0 font-semibold', transaction.is_ignored && 'text-muted-foreground')}
      />
    </>
  )

  if (selectable) {
    return (
      <label className="flex cursor-pointer items-center gap-3 px-3 py-3 hover:bg-muted/50">
        <Checkbox checked={selected} onCheckedChange={() => onToggle?.(transaction.id)} aria-label={`Selecionar ${transaction.description}`} />
        {content}
      </label>
    )
  }

  return (
    <Link to={`/transacoes/${transaction.id}`} className="flex items-center gap-3 px-3 py-3 hover:bg-muted/50">
      {content}
    </Link>
  )
}
