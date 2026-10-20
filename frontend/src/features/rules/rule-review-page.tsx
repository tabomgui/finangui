import { Check, CircleCheckBig, LoaderCircle, TriangleAlert, X } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { useCategories } from '@/api/queries/categories'
import { useApplyRuleReview, useRuleReview } from '@/api/queries/rules'
import type { Category, ReviewGroupKey, RuleReview, RuleReviewGroup, RuleReviewResult } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useSidebar } from '@/components/ui/sidebar'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { REVIEW_GROUP_ORDER } from './category-change-labels'
import { pluralize } from './rule-labels'
import { ReviewGroupCard } from './review-group-card'
import { defaultReviewSelection, pruneReviewSelection, reviewApplyItems, reviewGroupCheckState, toggleReviewGroup } from './review-selection'

const NO_CATEGORIES: Category[] = []
const NO_SELECTION: ReadonlySet<number> = new Set()

/** Grupos com lançamentos, na ordem da tela (fill, conflict, stale, manual). */
function visibleGroups(review: RuleReview | undefined): RuleReviewGroup[] {
  if (!review) return []
  const byKey = new Map(review.groups.map((group) => [group.key, group] as const))
  return REVIEW_GROUP_ORDER.map((key) => byKey.get(key)).filter((group): group is RuleReviewGroup => group !== undefined && group.count > 0)
}

function summary(groups: RuleReviewGroup[]): string {
  const total = groups.reduce((sum, group) => sum + group.count, 0)
  if (total === 0) return 'Regras e lançamentos de acordo'
  return `${total} ${pluralize(total, 'lançamento para revisar', 'lançamentos para revisar')}`
}

function resultMessage(result: RuleReviewResult): string {
  const applied = `${result.applied} ${pluralize(result.applied, 'aplicado', 'aplicados')}`
  if (result.skipped === 0) return applied
  return `${applied} · ${result.skipped} ${pluralize(result.skipped, 'ignorado porque mudou', 'ignorados porque mudaram')} desde a revisão`
}

type ActionBarProps = { count: number; pending: boolean; onApply: () => void; onClear: () => void }

/** Barra fixa da seleção, no lugar e no estilo da de Categorizar. */
function ReviewActionBar({ count, pending, onApply, onClear }: ActionBarProps) {
  const { state: sidebarState } = useSidebar()
  return (
    <div
      className={cn(
        'fixed inset-x-0 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-40 px-4 md:bottom-6',
        sidebarState === 'collapsed' ? 'md:pl-[var(--sidebar-width-icon)]' : 'md:pl-[var(--sidebar-width)]',
      )}
    >
      <div className="mx-auto flex max-w-3xl items-center gap-2 rounded-2xl border border-border bg-card p-3 shadow-lg">
        <span className="min-w-0 flex-1 truncate text-sm font-medium">
          {count} {pluralize(count, 'selecionado', 'selecionados')}
        </span>
        <Button type="button" variant="ghost" size="icon" aria-label="Limpar seleção" disabled={pending} onClick={onClear}>
          <X aria-hidden className="h-4 w-4" />
        </Button>
        <Button type="button" disabled={pending} onClick={onApply}>
          {pending ? <LoaderCircle aria-hidden className="h-4 w-4 animate-spin" /> : <Check aria-hidden className="h-4 w-4" />}
          Aplicar
        </Button>
      </div>
    </div>
  )
}

/**
 * Revisão de todas as regras ativas contra os lançamentos já existentes: o que o backend acha
 * diferente, por grupo. Só a categoria muda, e só nas linhas marcadas; o backend recalcula cada
 * uma ao aplicar e pula a que mudou desde que a tela carregou.
 */
export function RuleReviewPage() {
  const review = useRuleReview()
  const apply = useApplyRuleReview()
  const { data: categories = NO_CATEGORIES, isPending: categoriesLoading } = useCategories(true)
  const categoriesById = useMemo(() => new Map(categories.map((category) => [category.id, category] as const)), [categories])
  const [expandedKeys, setExpandedKeys] = useState<Set<ReviewGroupKey>>(new Set())
  // `null` até a primeira resposta: aí entra a seleção padrão. Depois de aplicar fica vazia, sem
  // voltar ao padrão quando a revisão recarrega.
  const [selection, setSelection] = useState<Set<number> | null>(null)
  const [confirming, setConfirming] = useState(false)

  const data = review.data
  const groups = useMemo(() => visibleGroups(data), [data])

  if (data && selection === null) {
    setSelection(defaultReviewSelection(data))
  }

  // Poda a seleção para as linhas da resposta atual (uma linha que sumiu não volta marcada depois).
  if (data && selection !== null) {
    const pruned = pruneReviewSelection(selection, data)
    if (pruned.size !== selection.size) setSelection(pruned)
  }

  const selectedIds: ReadonlySet<number> = selection ?? NO_SELECTION
  const selectedCount = selectedIds.size

  const toggleExpand = useCallback((key: ReviewGroupKey) => {
    setExpandedKeys((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }, [])

  const toggleItem = useCallback((id: number) => {
    setSelection((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])

  const toggleGroup = useCallback((group: RuleReviewGroup) => {
    setSelection((current) => toggleReviewGroup(current ?? NO_SELECTION, group))
  }, [])

  const confirmApply = async () => {
    if (!data) return
    const result = await apply.mutateAsync({ items: reviewApplyItems(data, selectedIds) })
    if (result.applied > 0) toast.success(resultMessage(result))
    else toast(resultMessage(result))
    // A revisão recarrega sozinha: `useApplyRuleReview` invalida as regras, raiz de `rules/review`.
    setSelection(new Set())
  }

  return (
    <>
      <PageHeader title="Revisar regras" back="/regras" subtitle={data ? summary(groups) : undefined} />
      <PageBody className={cn('space-y-3', selectedCount > 0 ? 'pb-40' : 'pb-28')}>
        {review.isError && !data ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível revisar as regras."
              action={
                <Button variant="outline" onClick={() => review.refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          </Card>
        ) : review.isPending ? (
          <div className="space-y-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16 w-full rounded-2xl" />
            ))}
          </div>
        ) : groups.length === 0 ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={CircleCheckBig}
              title="Tudo certo: as regras concordam com os lançamentos"
              description="Quando uma regra der uma categoria diferente da que um lançamento tem, ele aparece aqui."
            />
          </Card>
        ) : (
          <div aria-busy={review.isFetching} className={cn('space-y-3 transition-opacity', review.isFetching && 'opacity-60')}>
            {groups.map((group) => {
              const expanded = expandedKeys.has(group.key)
              return (
                <ReviewGroupCard
                  key={group.key}
                  group={group}
                  checkState={reviewGroupCheckState(group, selectedIds)}
                  selectedCount={group.applicable ? group.items.filter((item) => selectedIds.has(item.transaction_id)).length : 0}
                  selectedIds={expanded ? selectedIds : NO_SELECTION}
                  expanded={expanded}
                  disabled={apply.isPending}
                  categoriesById={categoriesById}
                  categoriesLoading={categoriesLoading}
                  onToggleExpand={toggleExpand}
                  onToggleGroup={toggleGroup}
                  onToggleItem={toggleItem}
                />
              )
            })}
          </div>
        )}
      </PageBody>

      {selectedCount > 0 && (
        <ReviewActionBar count={selectedCount} pending={apply.isPending} onApply={() => setConfirming(true)} onClear={() => setSelection(new Set())} />
      )}

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={`Aplicar ${selectedCount} ${pluralize(selectedCount, 'alteração', 'alterações')}?`}
        description="Só a categoria muda. Um lançamento que mudou desde a revisão é ignorado."
        confirmLabel="Aplicar"
        onConfirm={confirmApply}
      />
    </>
  )
}
