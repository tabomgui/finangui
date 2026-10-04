import type { ReportBasis } from '@/api/types'
import { Card } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { EVOLUTION_RANGE_OPTIONS, type EvolutionRangeKey } from './period'

const toggleItem =
  'h-auto rounded-xl border-2 border-border bg-card py-2 text-foreground disabled:opacity-100 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground'

type ReportFiltersProps = {
  range: EvolutionRangeKey
  onRangeChange: (range: EvolutionRangeKey) => void
  basis: ReportBasis
  onBasisChange: (basis: ReportBasis) => void
}

/**
 * Filtros do topo, acima dos dois relatórios (nunca dentro de um card de gráfico): o período
 * vale para a evolução mensal; a base de data (compra × fatura) vale para os dois relatórios.
 */
export function ReportFilters({ range, onRangeChange, basis, onBasisChange }: ReportFiltersProps) {
  return (
    <Card className="flex-col gap-3 rounded-2xl p-3 shadow-card sm:flex-row sm:items-center sm:justify-between">
      <Select value={range} onValueChange={(value) => onRangeChange(value as EvolutionRangeKey)}>
        <SelectTrigger aria-label="Período da evolução mensal" className="w-full bg-card sm:w-52">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {EVOLUTION_RANGE_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <ToggleGroup
        type="single"
        value={basis}
        onValueChange={(value) => value && onBasisChange(value as ReportBasis)}
        aria-label="Base de data das transações de cartão"
        className="grid w-full grid-cols-2 gap-2 sm:w-80"
      >
        <ToggleGroupItem value="purchase" className={toggleItem}>
          Data da compra
        </ToggleGroupItem>
        <ToggleGroupItem value="statement" className={toggleItem}>
          Vencimento da fatura
        </ToggleGroupItem>
      </ToggleGroup>
    </Card>
  )
}
