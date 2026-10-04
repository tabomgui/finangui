import { PiggyBank, Scale, Wallet } from 'lucide-react'
import { MoneyText } from '@/components/shared/money-text'
import { Card } from '@/components/ui/card'

type BudgetSummaryCardsProps = { budgeted: number; spent: number; currency: string }

export function BudgetSummaryCards({ budgeted, spent, currency }: BudgetSummaryCardsProps) {
  // Simples subtração de dois totais já prontos da API, só para exibição (como o percentual da
  // barra em `categoryShares`) — nenhuma regra nova: o backend não devolve "restante" agregado
  // porque ele já é trivial a partir de `totals.budgeted` e `totals.spent`.
  const remaining = budgeted - spent
  const items = [
    { label: 'Orçado', icon: PiggyBank, value: <MoneyText cents={budgeted} currency={currency} colored={false} /> },
    { label: 'Gasto', icon: Wallet, value: <MoneyText cents={spent} currency={currency} colored={false} /> },
    {
      label: 'Restante',
      icon: Scale,
      value: <MoneyText cents={remaining} currency={currency} colored={remaining < 0} />,
    },
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
