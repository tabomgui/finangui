import type { Card } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'

type CardLimitBarProps = Pick<Card, 'credit_limit' | 'used_limit' | 'available_limit' | 'limit' | 'currency'>

/** Proporções só para desenhar a barra; os valores vêm prontos do backend. */
function share(part: number, whole: number): string {
  if (whole <= 0) return '0%'
  return `${Math.min(Math.max(part / whole, 0), 1) * 100}%`
}

/** Mesma fonte de "usado"/"disponível" da fatura (`StatementLimitMeter`): `used_limit`/
 * `available_limit`, que já preferem o dado do banco quando a conta está sincronizada e caem no
 * calculado localmente senão — nunca `limit.used`/`limit.available`, que somam o projetado e por
 * isso não bateriam com o que a tela da fatura mostra. `limit.projected` continua sendo o único
 * extra: parcelas futuras não entram em `used_limit`. */
export function CardLimitBar({ credit_limit, used_limit, available_limit, limit, currency }: CardLimitBarProps) {
  const available = available_limit ?? limit.available
  const committed = used_limit + limit.projected
  const clampedCommitted = Math.min(Math.max(committed, 0), credit_limit)
  return (
    <div className="space-y-2">
      <div
        role="meter"
        aria-label="Uso do limite"
        aria-valuemin={0}
        aria-valuemax={credit_limit}
        aria-valuenow={clampedCommitted}
        aria-valuetext={`${formatMoney(clampedCommitted, currency)} de ${formatMoney(credit_limit, currency)}`}
        className="flex h-2.5 overflow-hidden rounded-full bg-muted"
      >
        <span className="bg-expense" style={{ width: share(used_limit, credit_limit) }} />
        <span className="bg-expense/40" style={{ width: share(limit.projected, credit_limit) }} />
      </div>
      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-xs">
        <dt className="text-muted-foreground">Usado</dt>
        <dd className="text-right">
          <MoneyText cents={used_limit} currency={currency} />
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
        <dd className={cn('text-right font-semibold', available < 0 && 'text-expense')}>
          <MoneyText cents={available} currency={currency} />
        </dd>
      </dl>
    </div>
  )
}
