import { Cell, Pie, PieChart, ResponsiveContainer, Sector, Tooltip } from 'recharts'
import type { SectorProps } from 'recharts'
import { entryColor } from './spending-colors'
import { entryLabel, type SpendingViewEntry } from './spending-shares'
import { formatMoney } from '@/lib/money'

type SliceDatum = SpendingViewEntry & { amountLabel: string }

type SpendingDonutProps = {
  entries: SpendingViewEntry[]
  total: number
  totalLabel: string
  currency: string
  highlightKey: string | null
  onSelect: (entry: SpendingViewEntry) => void
}

const FADED_OPACITY = 0.35

type SliceProps = {
  sectorProps: SectorProps
  payload: SliceDatum
  onSelect: (entry: SpendingViewEntry) => void
  highlightKey: string | null
}

/**
 * Renderização própria de cada fatia (em vez do `Sector` padrão do `Pie`): o `<g>` em volta leva
 * foco e teclado (`tabIndex`, `role="button"`, `onKeyDown`), já que o `Pie` sozinho só responde a
 * clique do mouse. `fillOpacity` esmaece a fatia quando outra está destacada (`highlightKey`).
 */
function Slice({ sectorProps, payload, onSelect, highlightKey }: SliceProps) {
  const faded = highlightKey !== null && highlightKey !== payload.key

  return (
    <g
      role="button"
      tabIndex={0}
      aria-label={`${entryLabel(payload)}, ${payload.amountLabel}, ${payload.percent}%`}
      onClick={() => onSelect(payload)}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onSelect(payload)
        }
      }}
      style={{ cursor: 'pointer', outline: 'none' }}
    >
      <Sector {...sectorProps} fillOpacity={faded ? FADED_OPACITY : 1} />
    </g>
  )
}

function DonutTooltip({ active, payload }: { active?: boolean; payload?: { payload: SliceDatum }[] }) {
  if (!active || !payload || payload.length === 0) return null
  const entry = payload[0].payload

  return (
    <div className="rounded-lg border bg-popover px-3 py-2 text-sm text-popover-foreground shadow-card">
      <p className="font-medium">{entryLabel(entry)}</p>
      <p className="text-muted-foreground">
        {entry.amountLabel} · {entry.percent}%
      </p>
    </div>
  )
}

/**
 * Donut da distribuição de gastos (lazy-loaded: ver `spending-card.tsx`). Total da entrada atual
 * no centro; tooltip e fatias mostram valor e percentual. Puramente visual quanto à seleção — a
 * fonte de verdade do destaque/detalhamento vive em `spending-card.tsx`; aqui só desenha o estado
 * recebido e avisa de volta por `onSelect`.
 */
export function SpendingDonut({ entries, total, totalLabel, currency, highlightKey, onSelect }: SpendingDonutProps) {
  const data: SliceDatum[] = entries.map((entry) => ({ ...entry, amountLabel: formatMoney(entry.amount, currency) }))

  return (
    <div className="relative mx-auto h-56 w-56">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie
            data={data}
            dataKey="amount"
            nameKey="key"
            innerRadius="65%"
            outerRadius="100%"
            paddingAngle={data.length > 1 ? 2 : 0}
            isAnimationActive={false}
            // `rawProps` não bate exatamente com nenhum tipo exportado pelo recharts para `shape`
            // (o payload de cada fatia é o nosso `SliceDatum`, não o genérico da lib) — `unknown`
            // aqui mantém a assinatura segura por fora; o `as` interno documenta a forma real.
            shape={(rawProps: unknown) => {
              const { payload, ...sectorProps } = rawProps as SectorProps & { payload: SliceDatum }
              return <Slice sectorProps={sectorProps} payload={payload} onSelect={onSelect} highlightKey={highlightKey} />
            }}
          >
            {data.map((entry) => (
              <Cell key={entry.key} fill={entryColor(entry.categoryId, entry.color)} stroke="var(--color-card)" strokeWidth={2} />
            ))}
          </Pie>
          <Tooltip content={<DonutTooltip />} />
        </PieChart>
      </ResponsiveContainer>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
        <span className="px-4 text-xs text-muted-foreground">{totalLabel}</span>
        <span className="text-lg font-semibold tabular-nums">{formatMoney(total, currency)}</span>
      </div>
    </div>
  )
}
