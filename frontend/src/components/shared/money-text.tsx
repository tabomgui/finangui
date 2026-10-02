import type { Direction } from '@/api/types'
import { formatMoney, formatSignedMoney } from '@/lib/money'
import { cn } from '@/lib/utils'

type MoneyTextProps = {
  cents: number
  currency?: string
  /** Com sentido: mostra sinal e cor de receita/despesa. Sem sentido: cor só se negativo. */
  direction?: Direction
  colored?: boolean
  className?: string
}

export function MoneyText({ cents, currency = 'BRL', direction, colored = true, className }: MoneyTextProps) {
  const text = direction ? formatSignedMoney(cents, direction, currency) : formatMoney(cents, currency)
  const tone = !colored
    ? undefined
    : direction === 'in'
      ? 'text-income'
      : direction === 'out' || cents < 0
        ? 'text-expense'
        : undefined

  return <span className={cn('tabular-nums', tone, className)}>{text}</span>
}
