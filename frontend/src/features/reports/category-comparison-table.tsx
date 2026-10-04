import type { CategoryComparisonItem } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { cn } from '@/lib/utils'

type CategoryComparisonTableProps = {
  items: CategoryComparisonItem[]
  totals: { a: number; b: number; delta: number; delta_percent?: number }
  currency: string
  labelA: string
  labelB: string
}

function deltaTone(delta: number) {
  // Despesa: gasto maior é pior (tom de despesa), gasto menor é melhor (tom de receita).
  return delta > 0 ? 'text-expense' : delta < 0 ? 'text-income' : undefined
}

function DeltaCell({ delta, deltaPercent, currency }: { delta: number; deltaPercent?: number; currency: string }) {
  return (
    <td className="px-3 py-2 text-right">
      <span className={cn('font-medium tabular-nums', deltaTone(delta))}>
        {delta > 0 && '+'}
        <MoneyText cents={delta} currency={currency} colored={false} />
      </span>
      {deltaPercent !== undefined && (
        <span className="ml-1 text-xs text-muted-foreground">
          ({deltaPercent > 0 ? '+' : ''}
          {deltaPercent}%)
        </span>
      )}
    </td>
  )
}

/**
 * Comparação de despesa por categoria entre dois períodos. `delta`/`delta_percent`, tanto por item
 * quanto nos totais, já vêm prontos da API (`b − a`); a cor da diferença só espelha o sentido já
 * calculado (sem nova regra): gasto maior no período B é ruim (tom de despesa), menor é bom (tom
 * de receita).
 */
export function CategoryComparisonTable({ items, totals, currency, labelA, labelB }: CategoryComparisonTableProps) {
  return (
    <div className="overflow-x-auto rounded-2xl shadow-card">
      <table className="w-full min-w-[420px] text-sm">
        <thead className="text-xs uppercase tracking-wide text-muted-foreground">
          <tr className="border-b border-border">
            <th className="px-3 py-2 text-left font-medium">Categoria</th>
            <th className="px-3 py-2 text-right font-medium">{labelA}</th>
            <th className="px-3 py-2 text-right font-medium">{labelB}</th>
            <th className="px-3 py-2 text-right font-medium">Diferença</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {items.map((item) => (
            <tr key={item.category_id ?? 'sem-categoria'}>
              <td className="px-3 py-2">
                <div className="flex items-center gap-2">
                  <CategoryIcon icon={item.icon} color={item.color} size="sm" />
                  <span className="truncate">{item.name}</span>
                </div>
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                <MoneyText cents={item.a} currency={currency} colored={false} />
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                <MoneyText cents={item.b} currency={currency} colored={false} />
              </td>
              <DeltaCell delta={item.delta} deltaPercent={item.delta_percent} currency={currency} />
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t border-border font-semibold">
            <td className="px-3 py-2">Total</td>
            <td className="px-3 py-2 text-right tabular-nums">
              <MoneyText cents={totals.a} currency={currency} colored={false} />
            </td>
            <td className="px-3 py-2 text-right tabular-nums">
              <MoneyText cents={totals.b} currency={currency} colored={false} />
            </td>
            <DeltaCell delta={totals.delta} deltaPercent={totals.delta_percent} currency={currency} />
          </tr>
        </tfoot>
      </table>
    </div>
  )
}
