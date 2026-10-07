import type { Card } from '@/api/types'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'

type StatementLimitMeterProps = Pick<Card, 'used_limit' | 'available_limit' | 'credit_limit'> & { currency: string }

/** Proporção só para desenhar a barra; os valores vêm prontos do backend. */
function share(used: number, limit: number): string {
  if (limit <= 0) return '0%'
  return `${Math.min(Math.max(used / limit, 0), 1) * 100}%`
}

/** Limite usado do cartão, ao lado (desktop) ou abaixo (mobile) do total da fatura. Some quando
 * não há limite algum (nem do banco, nem cadastrado) de onde derivar "de R$ Y". */
export function StatementLimitMeter({ used_limit, available_limit, credit_limit, currency }: StatementLimitMeterProps) {
  if (credit_limit <= 0) return null

  return (
    <div className="space-y-1.5 sm:w-56 sm:text-right">
      <p className="text-sm">
        Limite usado {formatMoney(used_limit, currency)} de {formatMoney(credit_limit, currency)}
      </p>
      <div
        role="meter"
        aria-label="Limite usado do cartão"
        aria-valuemin={0}
        aria-valuemax={credit_limit}
        aria-valuenow={Math.min(Math.max(used_limit, 0), credit_limit)}
        className="h-1.5 w-full overflow-hidden rounded-full bg-muted"
      >
        <span className="block h-full bg-expense" style={{ width: share(used_limit, credit_limit) }} />
      </div>
      {available_limit !== undefined && (
        <p className={cn('text-xs text-muted-foreground', available_limit < 0 && 'text-expense')}>
          Disponível {formatMoney(available_limit, currency)}
        </p>
      )}
    </div>
  )
}
