import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { CardStatement } from '@/api/types'
import { headerIconButton } from '@/components/layout/theme-toggle'
import { formatDate, formatMonth } from '@/lib/date'
import { cn } from '@/lib/utils'

type StatementNavProps = {
  statements: CardStatement[]
  selectedId: number
  onSelect: (id: number) => void
}

/** Navegação branca entre faturas, para ficar dentro do PageHeader (mesmo padrão do MonthNav do dashboard). */
export function StatementNav({ statements, selectedId, onSelect }: StatementNavProps) {
  const index = statements.findIndex((statement) => statement.id === selectedId)
  const selected = statements[index]
  const previous = index > 0 ? statements[index - 1] : null
  const next = index >= 0 && index < statements.length - 1 ? statements[index + 1] : null

  return (
    <div className="flex items-center justify-between gap-2">
      <button
        type="button"
        aria-label="Fatura anterior"
        disabled={!previous}
        className={cn(headerIconButton, 'disabled:pointer-events-none disabled:opacity-40')}
        onClick={() => previous && onSelect(previous.id)}
      >
        <ChevronLeft className="h-5 w-5" />
      </button>
      <div className="text-center">
        <p className="text-sm font-semibold">Fatura de {formatMonth(selected.due_date.slice(0, 7))}</p>
        <p className="text-xs text-white/80">Vence {formatDate(selected.due_date)}</p>
      </div>
      <button
        type="button"
        aria-label="Próxima fatura"
        disabled={!next}
        className={cn(headerIconButton, 'disabled:pointer-events-none disabled:opacity-40')}
        onClick={() => next && onSelect(next.id)}
      >
        <ChevronRight className="h-5 w-5" />
      </button>
    </div>
  )
}
