import { CalendarDays, Wallet } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'

// `.then` mantém o módulo com export nomeado (convenção do projeto: nunca `export default`) e
// ainda satisfaz o formato que `React.lazy` espera.
const BalanceDayPicker = lazy(() =>
  import('./balance-day-picker').then((m) => ({ default: m.BalanceDayPicker })),
)

/** Começa a baixar o chunk do calendário antes do clique (hover/foco no botão), sem abrir nada ainda. */
function prefetchDayPicker() {
  void import('./balance-day-picker')
}

type BalanceHeroProps = {
  totalBalance: number
  currency: string
  balanceDate: string
  onSelectDay: (day: string) => void
  onBackToToday: () => void
}

export function BalanceHero({ totalBalance, currency, balanceDate, onSelectDay, onBackToToday }: BalanceHeroProps) {
  const [open, setOpen] = useState(false)

  return (
    <div className="rounded-2xl border border-white/20 bg-white/10 p-5 backdrop-blur-sm dark:border-white/10 dark:bg-white/5">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            className="flex items-center gap-2 rounded-lg text-sm font-medium text-white/90 hover:text-white"
            onPointerEnter={prefetchDayPicker}
            onFocus={prefetchDayPicker}
          >
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white/20">
              <Wallet className="h-4 w-4" />
            </span>
            <span>Saldo em {formatDate(balanceDate)}</span>
            <CalendarDays className="h-4 w-4 text-white/70" />
          </button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-auto p-0">
          <Suspense fallback={<Skeleton className="m-3 h-72 w-64" />}>
            <BalanceDayPicker
              selected={balanceDate}
              onSelect={(day) => {
                onSelectDay(day)
                setOpen(false)
              }}
              onBackToToday={() => {
                onBackToToday()
                setOpen(false)
              }}
            />
          </Suspense>
        </PopoverContent>
      </Popover>
      <p className={cn('mt-3 text-3xl font-bold tabular-nums', totalBalance < 0 && 'text-red-200')}>
        {formatMoney(totalBalance, currency)}
      </p>
    </div>
  )
}
