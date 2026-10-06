import { Wallet } from 'lucide-react'
import { formatDate } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'

type BalanceHeroProps = {
  totalBalance: number
  currency: string
  balanceDate: string
}

export function BalanceHero({ totalBalance, currency, balanceDate }: BalanceHeroProps) {
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
    </div>
  )
}
