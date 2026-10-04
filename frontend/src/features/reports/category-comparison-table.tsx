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
    <td className="px-2 py-2 text-right sm:px-3">
      <span className={cn('font-medium tabular-nums', deltaTone(delta))}>
        {delta > 0 && '+'}
        <MoneyText cents={delta} currency={currency} colored={false} />
      </span>
      {/* O percentual some em telas estreitas (ver nota de largura abaixo): com 4 colunas de
          dinheiro, ele é o primeiro a sobrar fora de 390px; a diferença em si já é a informação
          principal. */}
      {deltaPercent !== undefined && (
        <span className="ml-1 hidden text-xs text-muted-foreground sm:inline">
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
 * de receita). `table-fixed` com larguras fixas por coluna (em vez de `min-w` + rolagem) é o que
 * cabe em 390px sem rolar: sem isso, o layout automático da tabela cresce pelo conteúdo e empurra
 * a página pra fora da viewport em telas pequenas. O card que envolve isto (`reports-page.tsx`)
 * já tem `rounded-2xl shadow-card`; repetir aqui dobraria sombra e borda.
 */
export function CategoryComparisonTable({ items, totals, currency, labelA, labelB }: CategoryComparisonTableProps) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full table-fixed text-sm">
        <colgroup>
          <col className="w-[38%]" />
          <col className="w-[18%]" />
          <col className="w-[18%]" />
          <col className="w-[26%]" />
        </colgroup>
        <thead className="text-xs uppercase tracking-wide text-muted-foreground">
          <tr className="border-b border-border">
            <th className="px-2 py-2 text-left font-medium sm:px-3">Categoria</th>
            <th className="px-2 py-2 text-right font-medium sm:px-3">{labelA}</th>
            <th className="px-2 py-2 text-right font-medium sm:px-3">{labelB}</th>
            <th className="px-2 py-2 text-right font-medium sm:px-3">Diferença</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {items.map((item) => (
            <tr key={item.category_id ?? 'sem-categoria'}>
              <td className="px-2 py-2 sm:px-3">
                <div className="flex min-w-0 items-center gap-2">
                  <CategoryIcon icon={item.icon} color={item.color} size="sm" />
                  <span className="min-w-0 truncate">{item.name}</span>
                </div>
              </td>
              <td className="px-2 py-2 text-right tabular-nums sm:px-3">
                <MoneyText cents={item.a} currency={currency} colored={false} />
              </td>
              <td className="px-2 py-2 text-right tabular-nums sm:px-3">
                <MoneyText cents={item.b} currency={currency} colored={false} />
              </td>
              <DeltaCell delta={item.delta} deltaPercent={item.delta_percent} currency={currency} />
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t border-border font-semibold">
            <td className="px-2 py-2 sm:px-3">Total</td>
            <td className="px-2 py-2 text-right tabular-nums sm:px-3">
              <MoneyText cents={totals.a} currency={currency} colored={false} />
            </td>
            <td className="px-2 py-2 text-right tabular-nums sm:px-3">
              <MoneyText cents={totals.b} currency={currency} colored={false} />
            </td>
            <DeltaCell delta={totals.delta} deltaPercent={totals.delta_percent} currency={currency} />
          </tr>
        </tfoot>
      </table>
    </div>
  )
}
