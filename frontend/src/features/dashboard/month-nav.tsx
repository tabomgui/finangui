import { ChevronLeft, ChevronRight } from 'lucide-react'
import { headerIconButton } from '@/components/layout/theme-toggle'
import { formatMonth, shiftMonth } from '@/lib/date'

type MonthNavProps = { month: string; onChange: (month: string) => void }

export function MonthNav({ month, onChange }: MonthNavProps) {
  return (
    <div className="flex items-center justify-between gap-2">
      <button type="button" aria-label="Mês anterior" className={headerIconButton} onClick={() => onChange(shiftMonth(month, -1))}>
        <ChevronLeft className="h-5 w-5" aria-hidden="true" />
      </button>
      <span className="text-sm font-semibold">{formatMonth(month)}</span>
      <button type="button" aria-label="Próximo mês" className={headerIconButton} onClick={() => onChange(shiftMonth(month, 1))}>
        <ChevronRight className="h-5 w-5" aria-hidden="true" />
      </button>
    </div>
  )
}
