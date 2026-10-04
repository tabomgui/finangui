import { Wand2 } from 'lucide-react'
import { memo } from 'react'
import type { ImportPreviewRow as ImportRow } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { formatDate } from '@/lib/date'
import { formatSignedMoney } from '@/lib/money'
import { cn } from '@/lib/utils'
import { matchText, OUTCOME_LABELS } from './import-labels'

type ImportPreviewRowProps = {
  row: ImportRow
  selected: boolean
  /** `false` para `duplicate`: a linha some do lote de qualquer jeito, então não há o que marcar. */
  selectable: boolean
  /** Nome da categoria sugerida (por uma regra ou pelo histórico — já resolvido pela página, para não repetir `useCategories` por linha). */
  categoryName?: string
  onToggle: (line: number) => void
}

/**
 * Uma linha da prévia de importação: desfecho, parcela, com o que casou e categoria sugerida.
 * `memo`: a página pode ter até 5000 linhas e troca `selectedLines` a cada clique num checkbox —
 * sem isso, cada clique re-renderizaria todas as linhas visíveis, não só a que mudou.
 */
export const ImportPreviewRow = memo(function ImportPreviewRow({
  row,
  selected,
  selectable,
  categoryName,
  onToggle,
}: ImportPreviewRowProps) {
  return (
    <label className={cn('flex items-center gap-3 px-3 py-3', selectable ? 'cursor-pointer hover:bg-muted/50' : 'opacity-60')}>
      <Checkbox
        checked={selected}
        disabled={!selectable}
        onCheckedChange={() => onToggle(row.line)}
        aria-label={`Selecionar ${row.description} em ${formatDate(row.date)}, ${formatSignedMoney(row.amount, row.direction)}`}
      />
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium">{row.description}</p>
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
          <Badge variant="outline">{OUTCOME_LABELS[row.outcome]}</Badge>
          {row.installment && (
            <span>
              Parcela {row.installment.number}/{row.installment.total}
            </span>
          )}
          {categoryName && (
            <span className="inline-flex items-center gap-1">
              <Wand2 className="h-3 w-3" />
              {categoryName}
            </span>
          )}
        </p>
        {row.match && <p className="truncate text-xs text-muted-foreground">{matchText(row.match)}</p>}
      </div>
      <MoneyText cents={row.amount} direction={row.direction} className="shrink-0 font-semibold" />
    </label>
  )
})
