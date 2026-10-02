import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight } from 'lucide-react'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'

export type TransactionKind = 'out' | 'in' | 'transfer'

const OPTIONS: { value: TransactionKind; label: string; icon: typeof ArrowUpRight }[] = [
  { value: 'out', label: 'Despesa', icon: ArrowUpRight },
  { value: 'in', label: 'Receita', icon: ArrowDownLeft },
  { value: 'transfer', label: 'Transferência', icon: ArrowLeftRight },
]

type KindToggleProps = { value: TransactionKind; onChange: (kind: TransactionKind) => void; disabled?: boolean }

export function KindToggle({ value, onChange, disabled }: KindToggleProps) {
  return (
    <ToggleGroup
      type="single"
      value={value}
      onValueChange={(next) => next && onChange(next as TransactionKind)}
      disabled={disabled}
      aria-label="Tipo de lançamento"
      className="grid w-full grid-cols-3 gap-2"
    >
      {OPTIONS.map(({ value: option, label, icon: Icon }) => (
        <ToggleGroupItem
          key={option}
          value={option}
          className="h-auto flex-col gap-1 rounded-xl border-2 border-border bg-card py-3 text-foreground disabled:opacity-100 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
        >
          <Icon className="h-5 w-5" />
          <span className="text-xs font-medium">{label}</span>
        </ToggleGroupItem>
      ))}
    </ToggleGroup>
  )
}
