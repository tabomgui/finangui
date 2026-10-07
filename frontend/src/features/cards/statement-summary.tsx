import { TriangleAlert } from 'lucide-react'
import type { CardStatement } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { formatDate } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { dueLabel } from './statement-labels'
import { StatementStatusBadge } from './statement-status-badge'

type StatementSummaryProps = {
  statement: CardStatement
  currency: string
  onPay: () => void
  onEditDates: () => void
}

export function StatementSummary({ statement, currency, onPay, onEditDates }: StatementSummaryProps) {
  return (
    <Card className="space-y-4 rounded-2xl p-4 shadow-card">
      <div className="flex items-center justify-between gap-2">
        <div>
          <p className="text-xs text-muted-foreground">Total da fatura</p>
          <MoneyText cents={statement.total} currency={currency} className="text-2xl font-bold" />
        </div>
        <StatementStatusBadge statement={statement} />
      </div>

      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
        <dt className="text-muted-foreground">Fechamento</dt>
        <dd className="text-right">{formatDate(statement.closing_date)}</dd>
        <dt className="text-muted-foreground">Vencimento</dt>
        <dd className="text-right">
          {formatDate(statement.due_date)} · {dueLabel(statement)}
        </dd>
        <dt className="text-muted-foreground">Pago</dt>
        <dd className="text-right">
          <MoneyText cents={statement.paid} currency={currency} />
        </dd>
        <dt className="text-muted-foreground">Restante</dt>
        <dd className="text-right">
          <MoneyText cents={statement.remaining} currency={currency} />
        </dd>
      </dl>

      {statement.has_divergence && statement.reported_total !== null && (
        <div className="flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            O banco informou {formatMoney(statement.reported_total, currency)}; o total calculado aqui é{' '}
            {formatMoney(statement.computed_total, currency)}.
          </p>
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        <Button disabled={statement.status !== 'open' && statement.remaining === 0} onClick={onPay}>
          Pagar fatura
        </Button>
        <Button variant="outline" onClick={onEditDates}>
          Editar datas
        </Button>
      </div>
    </Card>
  )
}
