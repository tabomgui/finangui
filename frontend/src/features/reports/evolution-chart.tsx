import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { MonthlyEvolutionMonth } from '@/api/types'
import { formatMonth } from '@/lib/date'
import { formatCompactMoney, formatMoney } from '@/lib/money'
import { EvolutionTooltip, type EvolutionTooltipProps } from './evolution-tooltip'
import { monthAxisLabel } from './period'

type EvolutionChartProps = { months: MonthlyEvolutionMonth[]; currency: string }

/**
 * Evolução mensal de receita, despesa e resultado: barras agrupadas (cores `--income`/`--expense`
 * do tema) com uma linha de resultado sobreposta, num único eixo de valor (as três séries são a
 * mesma grandeza — dinheiro — então duas escalas seriam enganosas). `role="img"` + tabela
 * visualmente oculta cobrem leitor de tela; o tooltip (`EvolutionTooltip`) mostra os valores por
 * extenso ao passar o mouse/focar uma barra.
 */
export function EvolutionChart({ months, currency }: EvolutionChartProps) {
  return (
    <div>
      <div role="img" aria-label="Gráfico de barras de receita e despesa por mês, com linha de resultado" className="h-72 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <ComposedChart data={months} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={2} barCategoryGap="24%">
            <CartesianGrid vertical={false} stroke="var(--color-border)" />
            <XAxis
              dataKey="month"
              tickFormatter={monthAxisLabel}
              tickLine={false}
              axisLine={false}
              tick={{ fill: 'var(--color-muted-foreground)', fontSize: 12 }}
            />
            <YAxis
              tickFormatter={(value: number) => formatCompactMoney(value, currency)}
              tickLine={false}
              axisLine={false}
              width={64}
              tick={{ fill: 'var(--color-muted-foreground)', fontSize: 12 }}
            />
            <Tooltip
              content={({ active, payload }) => (
                <EvolutionTooltip
                  active={active}
                  payload={payload as EvolutionTooltipProps['payload']}
                  currency={currency}
                />
              )}
              cursor={{ fill: 'var(--color-muted)' }}
            />
            <Legend />
            <Bar dataKey="income" name="Receita" fill="var(--color-income)" radius={[4, 4, 0, 0]} maxBarSize={24} />
            <Bar dataKey="expense" name="Despesa" fill="var(--color-expense)" radius={[4, 4, 0, 0]} maxBarSize={24} />
            <Line
              dataKey="net"
              name="Resultado"
              stroke="var(--color-foreground)"
              strokeWidth={2}
              dot={{ r: 4, fill: 'var(--color-foreground)', stroke: 'var(--color-card)', strokeWidth: 2 }}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </div>

      {/* `sr-only` num `<table>` não basta: tabela ignora `width: 1px` no layout automático e
          cresce pelo conteúdo, e um elemento `position: absolute` continua contando na largura
          rolável da página mesmo clipado visualmente — o `overflow-hidden` tem que vir de um
          contêiner em volta (div), não da própria tabela. */}
      <div className="sr-only">
        <table>
          <caption>Receita, despesa e resultado por mês</caption>
          <thead>
            <tr>
              <th>Mês</th>
              <th>Receita</th>
              <th>Despesa</th>
              <th>Resultado</th>
            </tr>
          </thead>
          <tbody>
            {months.map((month) => (
              <tr key={month.month}>
                <td>{formatMonth(month.month)}</td>
                <td>{formatMoney(month.income, currency)}</td>
                <td>{formatMoney(month.expense, currency)}</td>
                <td>{formatMoney(month.net, currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
