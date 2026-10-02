import { ArrowDownLeft, ArrowUpRight, Scale } from 'lucide-react'
import { MoneyText } from '@/components/shared/money-text'
import { Card } from '@/components/ui/card'

type SummaryCardsProps = { income: number; expense: number; net: number; currency: string }

export function SummaryCards({ income, expense, net, currency }: SummaryCardsProps) {
  const items = [
    { label: 'Receitas', icon: ArrowDownLeft, value: <MoneyText cents={income} currency={currency} direction="in" /> },
    { label: 'Despesas', icon: ArrowUpRight, value: <MoneyText cents={expense} currency={currency} direction="out" /> },
    { label: 'Resultado', icon: Scale, value: <MoneyText cents={net} currency={currency} /> },
  ]

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
      {items.map(({ label, icon: Icon, value }) => (
        <Card key={label} className="flex-row items-center justify-between gap-3 rounded-2xl p-4 shadow-card">
          <div>
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{label}</p>
            <p className="mt-1 text-xl font-bold">{value}</p>
          </div>
          <span className="rounded-lg bg-muted p-2">
            <Icon className="h-4 w-4 text-muted-foreground" />
          </span>
        </Card>
      ))}
    </div>
  )
}
