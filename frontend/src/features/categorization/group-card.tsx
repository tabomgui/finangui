import { Check, ChevronDown, LoaderCircle, Minus, Sparkles } from 'lucide-react'
import { memo, useId, useState } from 'react'
import type { Direction, PendingCategorizationGroup } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { pluralize } from '@/features/rules/rule-labels'
import { formatDate } from '@/lib/date'
import { cn } from '@/lib/utils'
import type { GroupCheckState } from './selection'

const VISIBLE_LIMIT = 8

const FOCUS_RING = 'outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

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
  /** Quantos lançamentos deste grupo estão marcados (já calculado pela página). */
  selectedCount: number
  /**
   * Seleção usada só nas linhas do grupo aberto. Cards fechados recebem um Set vazio
   * compartilhado, para que marcar um lançamento não mude as props deles.
   */
  selectedIds: ReadonlySet<number>
  expanded: boolean
  /** Caminho "Pai › Sub" da categoria sugerida pelo histórico, ou `null` sem sugestão. */
  suggestionLabel: string | null
  /** Pendente de aplicação (a sugestão deste grupo, nunca de outro). */
  applying: boolean
  /** Alguma aplicação em andamento (de qualquer grupo): trava todos os botões de sugestão. */
  suggestionDisabled: boolean
  onToggleExpand: (key: string) => void
  onToggleGroup: (group: PendingCategorizationGroup) => void
  onToggleTransaction: (id: number) => void
  onSelectDirection: (group: PendingCategorizationGroup, direction: Direction) => void
  onApplySuggestion: (group: PendingCategorizationGroup) => void
}

/**
 * Memoizado: com callbacks estáveis (recebem o grupo/chave) e, nos cards fechados, a contagem e o
 * estado do checkbox já calculados no lugar da seleção, marcar/desmarcar lançamentos ou abrir e
 * fechar um grupo só re-renderiza os cards cujo estado mudou. Iniciar ou terminar uma aplicação
 * muda `suggestionDisabled` de todos, então aí todos re-renderizam.
 */
export const GroupCard = memo(function GroupCard({
  group,
  checkState,
  selectedCount,
  selectedIds,
  expanded,
  suggestionLabel,
  applying,
  suggestionDisabled,
  onToggleExpand,
  onToggleGroup,
  onToggleTransaction,
  onSelectDirection,
  onApplySuggestion,
}: GroupCardProps) {
  const [showAll, setShowAll] = useState(false)
  const contentId = useId()

  const visible = showAll ? group.transactions : group.transactions.slice(0, VISIBLE_LIMIT)
  const hiddenCount = group.transactions.length - visible.length

  const total = group.transactions.length
  const countParts = [`${total} ${pluralize(total, 'lançamento', 'lançamentos')}`]
  if (group.out_count > 0) countParts.push(`${group.out_count} ${pluralize(group.out_count, 'saída', 'saídas')}`)
  if (group.in_count > 0) countParts.push(`${group.in_count} ${pluralize(group.in_count, 'entrada', 'entradas')}`)

  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-card">
      <div className={cn('flex items-center gap-3 px-3 py-3', expanded && 'border-b border-border')}>
        <GroupCheckbox state={checkState} label={`Selecionar grupo ${group.name}`} onClick={() => onToggleGroup(group)} />
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={expanded ? contentId : undefined}
          onClick={() => onToggleExpand(group.key)}
          className={cn('flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left', FOCUS_RING)}
        >
          <CategoryIcon icon={null} color={null} />
          <span className="min-w-0 flex-1">
            <span className="block truncate font-medium">{group.name}</span>
            <span className="block truncate text-xs text-muted-foreground">
              {countParts.join(' · ')}
              {selectedCount > 0 && (
                <>
                  {' · '}
                  <span className="text-primary">
                    {selectedCount} {pluralize(selectedCount, 'marcado', 'marcados')}
                  </span>
                </>
              )}
            </span>
          </span>
          <MoneyText
            cents={Math.abs(group.net)}
            direction={group.net >= 0 ? 'in' : 'out'}
            className="shrink-0 whitespace-nowrap text-right text-sm font-semibold"
          />
          <ChevronDown
            aria-hidden
            className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-180')}
          />
        </button>
      </div>

      {!expanded && suggestionLabel !== null && (
        <div className="flex items-center gap-2 border-t border-border bg-muted/30 px-3 py-2 text-xs">
          <Sparkles aria-hidden className="h-3.5 w-3.5 text-primary" />
          <span className="text-muted-foreground">Sugestão pelo histórico:</span>
          <span className="truncate font-medium">{suggestionLabel}</span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={applying || suggestionDisabled}
            onClick={() => onApplySuggestion(group)}
            className="ml-auto h-6 shrink-0 gap-1 rounded-full px-2.5 text-xs"
          >
            {applying && <LoaderCircle aria-hidden className="h-3 w-3 animate-spin" />}
            Aplicar a {total}
          </Button>
        </div>
      )}

      {expanded && (
        <div id={contentId}>
          {group.out_count > 0 && group.in_count > 0 && (
            <div className="flex flex-wrap items-center gap-2 px-3 pt-2 text-xs text-muted-foreground">
              <span>Marcar:</span>
              <button
                type="button"
                onClick={() => onSelectDirection(group, 'out')}
                className={cn('rounded-full border border-border px-2 py-0.5 hover:bg-muted', FOCUS_RING)}
              >
                só saídas ({group.out_count})
              </button>
              <button
                type="button"
                onClick={() => onSelectDirection(group, 'in')}
                className={cn('rounded-full border border-border px-2 py-0.5 hover:bg-muted', FOCUS_RING)}
              >
                só entradas ({group.in_count})
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
              <button
                type="button"
                onClick={() => setShowAll(true)}
                className={cn('rounded-md px-1 text-xs font-medium text-primary hover:underline', FOCUS_RING)}
              >
                + {hiddenCount} {pluralize(hiddenCount, 'lançamento', 'lançamentos')}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
})
