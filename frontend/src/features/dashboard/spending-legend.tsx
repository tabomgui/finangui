import { cn } from '@/lib/utils'
import { entryLabel, type SpendingViewEntry } from './spending-shares'

type SpendingLegendProps = {
  entries: SpendingViewEntry[]
  highlightKey: string | null
  onSelect: (entry: SpendingViewEntry) => void
}

/**
 * Chips clicáveis (e navegáveis por tab, por serem `<button>`) que espelham o destaque do donut —
 * o caminho de teclado da tela: as fatias do donut não entram no tab (`spending-donut.tsx`).
 * Uma categoria com subcategorias detalha ao clicar, em vez de alternar destaque: `aria-pressed`
 * não cabe nela (não é um toggle), por isso só aparece nas demais, e o rótulo avisa "ver
 * subcategorias" em vez de confiar só no texto visível do chip.
 */
export function SpendingLegend({ entries, highlightKey, onSelect }: SpendingLegendProps) {
  return (
    <ul className="flex flex-wrap justify-center gap-2" aria-label="Legenda do gráfico">
      {entries.map((entry) => {
        const dimmed = highlightKey !== null && highlightKey !== entry.key
        const drillable = entry.hasChildren

        return (
          <li key={entry.key}>
            <button
              type="button"
              data-chip-key={entry.key}
              onClick={() => onSelect(entry)}
              aria-pressed={drillable ? undefined : highlightKey === entry.key}
              aria-label={drillable ? `${entryLabel(entry)}, ${entry.percentLabel}, ver subcategorias` : undefined}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-opacity hover:bg-muted/50',
                dimmed && 'opacity-40',
              )}
            >
              <span aria-hidden className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: entry.displayColor }} />
              <span className="max-w-32 truncate">{entryLabel(entry)}</span>
              <span className="text-muted-foreground">{entry.percentLabel}</span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}
