import type { MonthlyEvolutionMonth } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { formatMonth } from '@/lib/date'

// Tipo próprio, não o `TooltipContentProps` do recharts: aquele exige vários campos internos
// (coordinate, accessibilityLayer, activeIndex...) irrelevantes aqui — só `active` e o `payload`
// do ponto ativo importam, e cada item carrega o mês inteiro em `payload` (nunca lido da série
// isolada, para não depender de qual barra/linha o mouse tocou).
export type EvolutionTooltipProps = {
  active?: boolean
  payload?: ReadonlyArray<{ payload?: MonthlyEvolutionMonth }>
  currency: string
}

type TooltipRowProps = { label: string; value: number; currency: string; swatch: string }

function TooltipRow({ label, value, currency, swatch }: TooltipRowProps) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="flex items-center gap-1.5 text-muted-foreground">
        <span aria-hidden className="h-0.5 w-3 shrink-0 rounded-full" style={{ backgroundColor: swatch }} />
        {label}
      </span>
      <MoneyText cents={value} currency={currency} colored={false} className="font-semibold text-foreground" />
    </div>
  )
}

/** Conteúdo do tooltip do gráfico de evolução mensal: lê o mês inteiro do ponto ativo, nunca das séries isoladas. */
export function EvolutionTooltip({ active, payload, currency }: EvolutionTooltipProps) {
  const row = active ? payload?.[0]?.payload : undefined
  if (!row) return null

  return (
    <div className="min-w-44 space-y-2 rounded-lg border border-border bg-card p-3 text-sm shadow-card">
      <p className="font-medium text-foreground">{formatMonth(row.month)}</p>
      <div className="space-y-1">
        <TooltipRow label="Receita" value={row.income} currency={currency} swatch="var(--color-income)" />
        <TooltipRow label="Despesa" value={row.expense} currency={currency} swatch="var(--color-expense)" />
        <TooltipRow label="Resultado" value={row.net} currency={currency} swatch="var(--color-foreground)" />
      </div>
    </div>
  )
}
