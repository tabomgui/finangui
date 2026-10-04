import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { monthName } from '@/lib/date'

/** Sem mês: padrão mensal (vale para todos os meses sem exceção própria). Com mês: exceção daquele mês. */
export type BudgetScope = 'default' | 'month'

type BudgetScopeToggleProps = {
  value: BudgetScope
  onChange: (scope: BudgetScope) => void
  month: string
  disabled?: boolean
  /** Sobrescreve o texto da opção "month" (padrão "Só {mês}"): usado quando ela não significa
   * remover uma exceção (ver `budget-delete-dialog.tsx`, categoria sem exceção própria). */
  monthLabel?: string
}

export function BudgetScopeToggle({ value, onChange, month, disabled, monthLabel }: BudgetScopeToggleProps) {
  return (
    <ToggleGroup
      type="single"
      value={value}
      onValueChange={(next) => next && onChange(next as BudgetScope)}
      disabled={disabled}
      aria-label="Vale para"
      className="grid w-full grid-cols-2 gap-2"
    >
      <ToggleGroupItem
        value="default"
        className="h-auto rounded-xl border-2 border-border bg-card py-2 text-foreground disabled:opacity-100 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
      >
        Todos os meses
      </ToggleGroupItem>
      <ToggleGroupItem
        value="month"
        className="h-auto rounded-xl border-2 border-border bg-card py-2 text-foreground disabled:opacity-100 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
      >
        {monthLabel ?? `Só ${monthName(month)}`}
      </ToggleGroupItem>
    </ToggleGroup>
  )
}
