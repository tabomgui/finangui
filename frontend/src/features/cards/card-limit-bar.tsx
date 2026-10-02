import type { Card } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { cn } from '@/lib/utils'

type CardLimitBarProps = Pick<Card, 'credit_limit' | 'limit' | 'currency'>

/** Proporções só para desenhar a barra; os valores vêm prontos do backend. */
function share(part: number, whole: number): string {
  if (whole <= 0) return '0%'
  return `${Math.min(Math.max(part / whole, 0), 1) * 100}%`
}

export function CardLimitBar({ credit_limit, limit, currency }: CardLimitBarProps) {
  const committed = limit.used + limit.projected
  return (
    <div className="space-y-2">
      <div
        role="meter"
        aria-label="Uso do limite"
        aria-valuemin={0}
        aria-valuemax={credit_limit}
        aria-valuenow={committed}
        className="flex h-2.5 overflow-hidden rounded-full bg-muted"
      >
        <span className="bg-expense" style={{ width: share(limit.used, credit_limit) }} />
        <span className="bg-expense/40" style={{ width: share(limit.projected, credit_limit) }} />
      </div>
      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-xs">
        <dt className="text-muted-foreground">Usado</dt>
        <dd className="text-right">
          <MoneyText cents={limit.used} currency={currency} />
        </dd>
        {limit.projected > 0 && (
          <>
            <dt className="text-muted-foreground">Parcelas futuras</dt>
            <dd className="text-right">
              <MoneyText cents={limit.projected} currency={currency} />
            </dd>
          </>
        )}
        <dt className="text-muted-foreground">Disponível</dt>
        <dd className={cn('text-right font-semibold', limit.available < 0 && 'text-expense')}>
          <MoneyText cents={limit.available} currency={currency} />
        </dd>
      </dl>
    </div>
  )
}
