import { ChevronDown } from 'lucide-react'
import { memo, useId, useState } from 'react'
import type { Category, RuleReviewGroup } from '@/api/types'
import { GroupCheckbox, type GroupCheckState } from '@/components/shared/group-checkbox'
import { cn } from '@/lib/utils'
import { launchCount, REVIEW_GROUP_HINTS, REVIEW_GROUP_LABELS } from './category-change-labels'
import { CategoryChangeRow } from './category-change-row'
import { pluralize } from './rule-labels'

const VISIBLE_LIMIT = 50

const FOCUS_RING = 'outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

type ReviewGroupCardProps = {
  group: RuleReviewGroup
  checkState: GroupCheckState
  selectedCount: number
  /** Seleção usada só nas linhas do grupo aberto (fechado, recebe um Set vazio compartilhado). */
  selectedIds: ReadonlySet<number>
  expanded: boolean
  disabled: boolean
  categoriesById: ReadonlyMap<number, Category>
  categoriesLoading: boolean
  onToggleExpand: (key: RuleReviewGroup['key']) => void
  onToggleGroup: (group: RuleReviewGroup) => void
  onToggleItem: (id: number) => void
}

/**
 * Um grupo da revisão de regras: cabeçalho recolhível com "selecionar tudo" de três estados (só
 * nos grupos aplicáveis) e as linhas "atual → nova". Memoizado como o `GroupCard` de Categorizar.
 */
export const ReviewGroupCard = memo(function ReviewGroupCard({
  group,
  checkState,
  selectedCount,
  selectedIds,
  expanded,
  disabled,
  categoriesById,
  categoriesLoading,
  onToggleExpand,
  onToggleGroup,
  onToggleItem,
}: ReviewGroupCardProps) {
  const [showAll, setShowAll] = useState(false)
  const contentId = useId()
  const label = REVIEW_GROUP_LABELS[group.key]

  const visible = showAll ? group.items : group.items.slice(0, VISIBLE_LIMIT)
  const hiddenCount = group.items.length - visible.length

  return (
    <section aria-label={label} className="overflow-hidden rounded-2xl border border-border bg-card shadow-card">
      <div className={cn('flex items-center gap-3 px-3 py-3', expanded && 'border-b border-border')}>
        {group.applicable ? (
          <GroupCheckbox state={checkState} label={`Selecionar todos em ${label}`} disabled={disabled} onClick={() => onToggleGroup(group)} />
        ) : (
          // Mesmo recuo dos grupos com "selecionar tudo", para os títulos ficarem alinhados.
          <span aria-hidden className="h-5 w-5 shrink-0" />
        )}
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={expanded ? contentId : undefined}
          onClick={() => onToggleExpand(group.key)}
          className={cn('flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left', FOCUS_RING)}
        >
          <span className="min-w-0 flex-1">
            <span className="block font-medium">{label}</span>
            <span className="block text-xs text-muted-foreground">
              {launchCount(group.count)}
              {/* No cabeçalho (visível fechado também): "selecionar tudo" só marca os itens que vieram. */}
              {group.truncated && ` · mostrando os ${group.items.length} mais recentes de ${group.count}`}
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
          <ChevronDown aria-hidden className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-180')} />
        </button>
      </div>

      {expanded && (
        <div id={contentId}>
          <p className="px-3 pt-2 text-xs text-muted-foreground">{REVIEW_GROUP_HINTS[group.key]}</p>
          <ul className="mt-2 divide-y divide-border border-t border-border">
            {visible.map((item) => (
              <li key={item.transaction_id}>
                <CategoryChangeRow
                  item={item}
                  categoriesById={categoriesById}
                  categoriesLoading={categoriesLoading}
                  showOrigin
                  selection={
                    group.applicable ? { checked: selectedIds.has(item.transaction_id), disabled, onToggle: onToggleItem } : undefined
                  }
                />
              </li>
            ))}
          </ul>
          {hiddenCount > 0 && (
            <div className="border-t border-border px-3 py-2 text-center">
              <button
                type="button"
                onClick={() => setShowAll(true)}
                className={cn('rounded-md px-1 text-xs font-medium text-primary hover:underline', FOCUS_RING)}
              >
                + {launchCount(hiddenCount)}
              </button>
            </div>
          )}
        </div>
      )}
    </section>
  )
})
