import { ArrowLeftRight, Banknote } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'
import type { Transaction } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'
import { CategorizationBadge } from './categorization-badge'
import { transactionSubtitle } from './transaction-subtitle'

type TransactionRowProps = {
  transaction: Transaction
  selectable?: boolean
  selected?: boolean
  onToggle?: (id: number) => void
}

export function TransactionRow({ transaction, selectable = false, selected = false, onToggle }: TransactionRowProps) {
  const location = useLocation()
  const isTransfer = transaction.transfer_id !== null
  // Categoria "efetivamente transferência" (ex.: ajuste entre contas feito via categoria, não via
  // o fluxo de transferência) recebe a mesma cor neutra, mas mantém o próprio nome na legenda.
  const isTransferEffective = transaction.category?.is_transfer_effective ?? false
  const subtitle = transactionSubtitle(transaction)

  const content = (
    <>
      {transaction.is_card_payment ? (
        <span aria-hidden className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Banknote className="h-5 w-5" />
        </span>
      ) : isTransfer ? (
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
          <CategorizationBadge categorization={transaction.categorization} />
          {transaction.status === 'pending' && <Badge variant="outline">Pendente</Badge>}
          {transaction.status === 'projected' && <Badge variant="outline">Prevista</Badge>}
          {transaction.is_ignored && <Badge variant="secondary">Ignorada</Badge>}
          {transaction.is_ignored && transaction.ignored_reason && (
            <span className="min-w-0 truncate">{transaction.ignored_reason}</span>
          )}
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
        colored={!isTransfer && !isTransferEffective && !transaction.is_ignored}
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
    <Link
      to={`/transacoes/${transaction.id}`}
      state={{ from: location.pathname + location.search }}
      className="flex items-center gap-3 px-3 py-3 hover:bg-muted/50"
    >
      {content}
    </Link>
  )
}
