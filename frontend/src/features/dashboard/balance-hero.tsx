import { Wallet } from 'lucide-react'
import { formatDate, formatDayMonth, monthName, monthRange } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'

type BalanceHeroProps = {
  totalBalance: number
  currency: string
  balanceDate: string
  /** Ausente em meses passados (ver `MonthSummary.projected_balance` no backend). */
  projectedBalance?: number
  /** Mês mostrado no momento ("YYYY-MM"), para rotular o saldo previsto. */
  month: string
  currentMonth: string
}

/** "Previsto para 31/10" no mês atual; "Previsto para o fim de novembro" no mês seguinte. */
function projectedBalanceLabel(month: string, currentMonth: string): string {
  if (month === currentMonth) return `Previsto para ${formatDayMonth(monthRange(month).to)}`
  return `Previsto para o fim de ${monthName(month)}`
}

export function BalanceHero({ totalBalance, currency, balanceDate, projectedBalance, month, currentMonth }: BalanceHeroProps) {
  return (
    <div className="rounded-2xl border border-white/20 bg-white/10 p-5 backdrop-blur-sm dark:border-white/10 dark:bg-white/5">
      <p className="flex items-center gap-2 text-sm font-medium text-white/90">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white/20">
          <Wallet className="h-4 w-4" />
        </span>
        Saldo em {formatDate(balanceDate)}
      </p>
      <p className={cn('mt-3 text-3xl font-bold tabular-nums', totalBalance < 0 && 'text-red-200')}>
        {formatMoney(totalBalance, currency)}
      </p>
      {projectedBalance !== undefined && (
        <p className="mt-2 text-sm text-white/80">
          {projectedBalanceLabel(month, currentMonth)}:{' '}
          <span className="font-semibold tabular-nums">{formatMoney(projectedBalance, currency)}</span>
        </p>
      )}
    </div>
  )
}
