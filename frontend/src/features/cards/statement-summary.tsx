import { Info, TriangleAlert } from 'lucide-react'
import type { Card as CardType, CardStatement } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { formatDate } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { dueLabel } from './statement-labels'
import { StatementLimitMeter } from './statement-limit-meter'
import { StatementStatusBadge } from './statement-status-badge'

type StatementSummaryProps = {
  statement: CardStatement
  currency: string
  limit: Pick<CardType, 'used_limit' | 'available_limit' | 'credit_limit'>
  onPay: () => void
  onEditDates: () => void
}

export function StatementSummary({ statement, currency, limit, onPay, onEditDates }: StatementSummaryProps) {
  // Fatura aberta com restante zerado: já está em dia, mas continua aberta — uma nova compra
  // pode voltar a aumentar o restante, então o botão de pagar segue habilitado (ver PayStatement).
  const isSettledOpen = statement.status === 'open' && statement.remaining === 0

  return (
    <Card className="space-y-4 rounded-2xl p-4 shadow-card">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-center justify-between gap-2 sm:justify-start sm:gap-4">
          <div>
            <p className="text-xs text-muted-foreground">Total da fatura</p>
            <MoneyText cents={statement.total} currency={currency} className="text-2xl font-bold" />
          </div>
          <StatementStatusBadge statement={statement} />
        </div>
        <StatementLimitMeter {...limit} currency={currency} />
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

      {isSettledOpen && (
        <p className="flex items-start gap-2 text-sm text-muted-foreground">
          <Info className="mt-0.5 h-4 w-4 shrink-0" />
          Paga por enquanto: a fatura continua aberta e novas compras aumentam o restante.
        </p>
      )}

      {statement.has_divergence && statement.reported_total !== null && (
        <div className="flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            O banco informou {formatMoney(statement.reported_total, currency)}; o total calculado aqui é{' '}
            {formatMoney(statement.computed_total, currency)}.
          </p>
        </div>
      )}

      {statement.history_incomplete && statement.history_incomplete_since && (
        <div className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
          <Info className="mt-0.5 h-4 w-4 shrink-0" />
          <p>Os lançamentos desta fatura são anteriores ao histórico compartilhado pelo banco (desde {formatDate(statement.history_incomplete_since)}).</p>
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
