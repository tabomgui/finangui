import { Check, ChevronDown, LoaderCircle, Minus, Sparkles } from 'lucide-react'
import { useId, useState } from 'react'
import type { Category, Direction, PendingCategorizationGroup } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { formatDate } from '@/lib/date'
import { cn } from '@/lib/utils'
import type { GroupCheckState } from './selection'

const VISIBLE_LIMIT = 8

type GroupCheckboxProps = { state: GroupCheckState; label: string; onClick: () => void }

/**
 * Checkbox de três estados do cabeçalho do grupo: o `Checkbox` do shadcn (ui/checkbox.tsx) não
 * distingue "parcial" visualmente (sempre mostra o mesmo ícone de check), então o cabeçalho usa
 * um botão próprio com `role="checkbox"`/`aria-checked="mixed"`, o padrão de acessibilidade para
 * esse terceiro estado.
 */
function GroupCheckbox({ state, label, onClick }: GroupCheckboxProps) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={state === 'all' ? true : state === 'partial' ? 'mixed' : false}
      aria-label={label}
      onClick={onClick}
      className={cn(
        'flex h-5 w-5 shrink-0 items-center justify-center rounded-[4px] border outline-none transition-shadow focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
        state === 'none' ? 'border-input' : 'border-primary bg-primary text-primary-foreground',
      )}
    >
      {state === 'all' && <Check aria-hidden className="h-3.5 w-3.5" />}
      {state === 'partial' && <Minus aria-hidden className="h-3.5 w-3.5" />}
    </button>
  )
}

type GroupCardProps = {
  group: PendingCategorizationGroup
  checkState: GroupCheckState
  selectedIds: ReadonlySet<number>
  expanded: boolean
  suggestionCategory: Category | null
  /** Pendente de aplicação (a sugestão deste grupo, nunca de outro). */
  applying: boolean
  onToggleExpand: () => void
  onToggleGroup: () => void
  onToggleTransaction: (id: number) => void
  onSelectDirection: (direction: Direction) => void
  onApplySuggestion: () => void
}

export function GroupCard({
  group,
  checkState,
  selectedIds,
  expanded,
  suggestionCategory,
  applying,
  onToggleExpand,
  onToggleGroup,
  onToggleTransaction,
  onSelectDirection,
  onApplySuggestion,
}: GroupCardProps) {
  const [showAll, setShowAll] = useState(false)
  const contentId = useId()

  const outs = group.transactions.filter((transaction) => transaction.direction === 'out')
  const ins = group.transactions.filter((transaction) => transaction.direction === 'in')
  const selectedCount = group.transactions.filter((transaction) => selectedIds.has(transaction.id)).length
  const visible = showAll ? group.transactions : group.transactions.slice(0, VISIBLE_LIMIT)
  const hiddenCount = group.transactions.length - visible.length

  const countParts = [`${group.transactions.length} lançamento${group.transactions.length === 1 ? '' : 's'}`]
  if (outs.length > 0) countParts.push(`${outs.length} saída${outs.length === 1 ? '' : 's'}`)
  if (ins.length > 0) countParts.push(`${ins.length} entrada${ins.length === 1 ? '' : 's'}`)
  if (selectedCount > 0) countParts.push(`${selectedCount} marcado${selectedCount === 1 ? '' : 's'}`)

  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-card">
      <div className={cn('flex items-center gap-3 px-3 py-3', expanded && 'border-b border-border')}>
        <GroupCheckbox state={checkState} label={`Selecionar grupo ${group.name}`} onClick={onToggleGroup} />
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={contentId}
          onClick={onToggleExpand}
          className="flex min-w-0 flex-1 items-center gap-3 text-left"
        >
          <CategoryIcon icon={null} color={null} />
          <span className="min-w-0 flex-1">
            <span className="block truncate font-medium">{group.name}</span>
            <span className="block truncate text-xs text-muted-foreground">{countParts.join(' · ')}</span>
          </span>
          <span className="hidden shrink-0 text-right text-sm font-semibold sm:block">
            <MoneyText cents={Math.abs(group.net)} direction={group.net >= 0 ? 'in' : 'out'} />
          </span>
          <ChevronDown
            aria-hidden
            className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-180')}
          />
        </button>
      </div>

      {!expanded && suggestionCategory && (
        <div className="flex items-center gap-2 border-t border-border bg-muted/30 px-3 py-2 text-xs">
          <Sparkles aria-hidden className="h-3.5 w-3.5 text-primary" />
          <span className="text-muted-foreground">Sugestão pelo histórico:</span>
          <span className="truncate font-medium">{suggestionCategory.name}</span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={applying}
            onClick={onApplySuggestion}
            className="ml-auto h-6 shrink-0 gap-1 rounded-full px-2.5 text-xs"
          >
            {applying && <LoaderCircle aria-hidden className="h-3 w-3 animate-spin" />}
            Aplicar a {group.transactions.length}
          </Button>
        </div>
      )}

      {expanded && (
        <div id={contentId}>
          {outs.length > 0 && ins.length > 0 && (
            <div className="flex flex-wrap items-center gap-2 px-3 pt-2 text-xs text-muted-foreground">
              <span>Marcar:</span>
              <button
                type="button"
                onClick={() => onSelectDirection('out')}
                className="rounded-full border border-border px-2 py-0.5 hover:bg-muted"
              >
                só saídas ({outs.length})
              </button>
              <button
                type="button"
                onClick={() => onSelectDirection('in')}
                className="rounded-full border border-border px-2 py-0.5 hover:bg-muted"
              >
                só entradas ({ins.length})
              </button>
            </div>
          )}
          <ul className="divide-y divide-border">
            {visible.map((transaction) => (
              <li key={transaction.id}>
                <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-muted/50">
                  <Checkbox
                    checked={selectedIds.has(transaction.id)}
                    onCheckedChange={() => onToggleTransaction(transaction.id)}
                    aria-label={`Selecionar ${transaction.description}`}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm">{transaction.description}</span>
                    <span className="block text-xs text-muted-foreground">
                      {formatDate(transaction.date)} · {transaction.account_name}
                    </span>
                  </span>
                  <MoneyText
                    cents={transaction.amount}
                    direction={transaction.direction}
                    className="shrink-0 text-sm font-semibold"
                  />
                </label>
              </li>
            ))}
          </ul>
          {hiddenCount > 0 && (
            <div className="px-3 py-2 text-center">
              <button type="button" onClick={() => setShowAll(true)} className="text-xs font-medium text-primary hover:underline">
                + {hiddenCount} lançamentos
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
