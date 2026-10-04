import type { ReactNode } from 'react'
import type { CategoryComparisonItem } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { cn } from '@/lib/utils'

type Totals = { a: number; b: number; delta: number; delta_percent?: number }

type CategoryComparisonTableProps = {
  items: CategoryComparisonItem[]
  totals: Totals
  currency: string
  labelA: string
  labelB: string
}

function deltaTone(delta: number) {
  // Despesa: gasto maior é pior (tom de despesa), gasto menor é melhor (tom de receita).
  return delta > 0 ? 'text-expense' : delta < 0 ? 'text-income' : undefined
}

function DeltaValue({ delta, deltaPercent, currency }: { delta: number; deltaPercent?: number; currency: string }) {
  return (
    <>
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
    </>
  )
}

function CategoryLabel({ item }: { item: CategoryComparisonItem }) {
  return (
    <div className="flex min-w-0 items-center gap-2">
      <CategoryIcon icon={item.icon} color={item.color} size="sm" />
      <span className="min-w-0 truncate">{item.name}</span>
    </div>
  )
}

type ListRowProps = {
  name: ReactNode
  a: number
  b: number
  delta: number
  deltaPercent?: number
  currency: string
  labelA: string
  labelB: string
  emphasize?: boolean
}

function ListRow({ name, a, b, delta, deltaPercent, currency, labelA, labelB, emphasize }: ListRowProps) {
  return (
    <li className={cn('space-y-1 px-3 py-3', emphasize && 'font-semibold')}>
      {name}
      {/* Duas linhas, não uma só com `justify-between`: "A → B" pode ser mais largo que a tela
          (valores grandes), e quebrar dentro de uma linha flex jogaria a diferença pro início em
          vez de mantê-la à direita — um bloco de largura total por baixo garante isso sempre. */}
      <p className="text-sm font-normal text-muted-foreground tabular-nums">
        {labelA} <MoneyText cents={a} currency={currency} colored={false} /> {'→'} {labelB}{' '}
        <MoneyText cents={b} currency={currency} colored={false} />
      </p>
      <p className="text-right text-sm font-normal tabular-nums">
        <DeltaValue delta={delta} deltaPercent={deltaPercent} currency={currency} />
      </p>
    </li>
  )
}

type RowsProps = { items: CategoryComparisonItem[]; totals: Totals; currency: string; labelA: string; labelB: string }

/**
 * Abaixo de `sm`, quatro colunas de dinheiro lado a lado não cabem em 390px sem cortar ou
 * sobrepor texto (ver `ComparisonTable`): cada categoria vira duas linhas — nome, depois os dois
 * períodos num traço só ("A → B") com a diferença alinhada à direita.
 */
function ComparisonList({ items, totals, currency, labelA, labelB }: RowsProps) {
  return (
    <ul className="divide-y divide-border sm:hidden">
      {items.map((item) => (
        <ListRow
          key={item.category_id ?? 'sem-categoria'}
          name={<CategoryLabel item={item} />}
          a={item.a}
          b={item.b}
          delta={item.delta}
          deltaPercent={item.delta_percent}
          currency={currency}
          labelA={labelA}
          labelB={labelB}
        />
      ))}
      <ListRow
        name="Total"
        a={totals.a}
        b={totals.b}
        delta={totals.delta}
        deltaPercent={totals.delta_percent}
        currency={currency}
        labelA={labelA}
        labelB={labelB}
        emphasize
      />
    </ul>
  )
}

function ComparisonTable({ items, totals, currency, labelA, labelB }: RowsProps) {
  return (
    <div className="hidden overflow-x-auto sm:block">
      <table className="w-full table-fixed text-sm">
        <colgroup>
          <col className="w-[38%]" />
          <col className="w-[18%]" />
          <col className="w-[18%]" />
          <col className="w-[26%]" />
        </colgroup>
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
                <CategoryLabel item={item} />
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                <MoneyText cents={item.a} currency={currency} colored={false} />
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                <MoneyText cents={item.b} currency={currency} colored={false} />
              </td>
              <td className="px-3 py-2 text-right">
                <DeltaValue delta={item.delta} deltaPercent={item.delta_percent} currency={currency} />
              </td>
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
            <td className="px-3 py-2 text-right">
              <DeltaValue delta={totals.delta} deltaPercent={totals.delta_percent} currency={currency} />
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  )
}

/**
 * Comparação de despesa por categoria entre dois períodos. `delta`/`delta_percent`, tanto por item
 * quanto nos totais, já vêm prontos da API (`b − a`); a cor da diferença só espelha o sentido já
 * calculado (sem nova regra): gasto maior no período B é ruim (tom de despesa), menor é bom (tom
 * de receita). Duas representações dos mesmos dados, uma visível por vez via classe responsiva
 * (`ComparisonList` abaixo de `sm`, `ComparisonTable` a partir daí): quatro colunas de dinheiro
 * lado a lado não cabem em 390px sem cortar ou sobrepor texto.
 */
export function CategoryComparisonTable({ items, totals, currency, labelA, labelB }: CategoryComparisonTableProps) {
  return (
    <div>
      <ComparisonList items={items} totals={totals} currency={currency} labelA={labelA} labelB={labelB} />
      <ComparisonTable items={items} totals={totals} currency={currency} labelA={labelA} labelB={labelB} />
    </div>
  )
}
