import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatMonth, shiftMonth } from '@/lib/date'

type MonthStepperProps = { label: string; month: string; onChange: (month: string) => void }

// Variante de `MonthNav` para a área de conteúdo (fundo claro): `MonthNav` usa botões translúcidos
// pensados para a faixa esmeralda do cabeçalho, ilegíveis sobre o card branco dos filtros.
function MonthStepper({ label, month, onChange }: MonthStepperProps) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{label}</p>
      <div className="flex items-center justify-between gap-2 rounded-xl border border-border bg-card px-2 py-1.5">
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={`${label}: mês anterior`}
          onClick={() => onChange(shiftMonth(month, -1))}
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>
        <span className="text-sm font-medium">{formatMonth(month)}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={`${label}: próximo mês`}
          onClick={() => onChange(shiftMonth(month, 1))}
        >
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}

type ComparisonPeriodPickerProps = {
  monthA: string
  monthB: string
  onChangeA: (month: string) => void
  onChangeB: (month: string) => void
}

/** Os dois meses comparados: A (antes) e B (depois) — `delta` da API é sempre B − A. */
export function ComparisonPeriodPicker({ monthA, monthB, onChangeA, onChangeB }: ComparisonPeriodPickerProps) {
  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      <MonthStepper label="Período A" month={monthA} onChange={onChangeA} />
      <MonthStepper label="Período B" month={monthB} onChange={onChangeB} />
    </div>
  )
}
