import { entryColor } from './spending-colors'
import { entryLabel, type SpendingViewEntry } from './spending-shares'
import { cn } from '@/lib/utils'

type SpendingLegendProps = {
  entries: SpendingViewEntry[]
  highlightKey: string | null
  onSelect: (entry: SpendingViewEntry) => void
}

/** Chips clicáveis (e navegáveis por tab, por serem `<button>`) que espelham o destaque do donut. */
export function SpendingLegend({ entries, highlightKey, onSelect }: SpendingLegendProps) {
  return (
    <ul className="flex flex-wrap justify-center gap-2" aria-label="Legenda do gráfico">
      {entries.map((entry) => {
        const dimmed = highlightKey !== null && highlightKey !== entry.key
        return (
          <li key={entry.key}>
            <button
              type="button"
              onClick={() => onSelect(entry)}
              aria-pressed={highlightKey === entry.key}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-opacity hover:bg-muted/50',
                dimmed && 'opacity-40',
              )}
            >
              <span aria-hidden className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: entryColor(entry.categoryId, entry.color) }} />
              <span className="max-w-32 truncate">{entryLabel(entry)}</span>
              <span className="text-muted-foreground">{entry.percent}%</span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}
