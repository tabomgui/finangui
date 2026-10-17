import { PartyPopper, Search, Tags, TriangleAlert } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { useApplyCategorization, usePendingCategorization, usePendingCategorizationCount } from '@/api/queries/categorization'
import { useCategories } from '@/api/queries/categories'
import type { Category, Direction, PendingCategorizationGroup } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { pluralize } from '@/features/rules/rule-labels'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'
import { categoryPath } from './category-path'
import { CategorizeActionBar } from './categorize-action-bar'
import { GroupCard } from './group-card'
import { pendingCategorizationLabel } from './pending-label'
import { groupCheckState, selectGroupDirection, toggleGroupSelection } from './selection'

type FilterValue = 'all' | 'out' | 'in'

const NO_CATEGORIES: Category[] = []
const NO_GROUPS: PendingCategorizationGroup[] = []
/** Seleção passada aos cards fechados: eles não mostram linhas, então não precisam da real. */
const NO_SELECTION: ReadonlySet<number> = new Set()

export function CategorizePage() {
  const [search, setSearch] = useState('')
  const [filterValue, setFilterValue] = useState<FilterValue>('all')
  const [expandedKeys, setExpandedKeys] = useState<Set<string>>(new Set())
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const [applyingGroupKey, setApplyingGroupKey] = useState<string | null>(null)
  // Baseline da barra de progresso: capturado na primeira resposta *fresca* (não a de cache de
  // montagem, que a Início e Transações já deixam lá) e nunca mais tocado por filtro/busca — só
  // avança quando lançamentos são de fato categorizados (ver `done` abaixo). O
  // `refetchOnMount: 'always'` garante essa resposta fresca logo na entrada; até ela chegar, a
  // barra fica oculta.
  const [initialTotal, setInitialTotal] = useState<number | null>(null)

  const countQuery = usePendingCategorizationCount({ refetchOnMount: 'always' })
  const direction: Direction | undefined = filterValue === 'all' ? undefined : filterValue
  const debouncedSearch = useDebouncedValue(search, 350)
  const pendingQuery = usePendingCategorization({ direction, search: debouncedSearch.trim() || undefined })
  const { data: categories = NO_CATEGORIES } = useCategories()
  // `mutateAsync` é estável entre renders (o objeto do `useMutation` não é): os callbacks
  // passados ao `GroupCard` memoizado dependem só dele.
  const { mutateAsync: applyCategorization, isPending: applyPending } = useApplyCategorization()

  const categoriesById = useMemo(() => new Map(categories.map((category) => [category.id, category] as const)), [categories])

  if (initialTotal === null && countQuery.data && countQuery.isFetchedAfterMount) {
    setInitialTotal(countQuery.data.total_pending)
  }

  const totalPending = countQuery.data?.total_pending ?? 0
  const done = initialTotal === null ? 0 : Math.max(initialTotal - totalPending, 0)
  const progressPercent = initialTotal !== null && initialTotal > 0 ? Math.round((done / initialTotal) * 100) : 100

  const groups = pendingQuery.data?.groups ?? NO_GROUPS
  // Memoizado: a contagem local da regra (barra de ação) depende desta referência.
  const loaded = useMemo(() => groups.flatMap((group) => group.transactions), [groups])
  // A lista é só um recorte dos pendentes (busca, resposta truncada ou a resposta anterior ainda
  // na tela enquanto a nova carrega): a contagem da regra não pode contar só o que aparece.
  const partialList = debouncedSearch.trim() !== '' || pendingQuery.data?.truncated === true || pendingQuery.isPlaceholderData
  const filtered = search.trim() !== '' || filterValue !== 'all'

  // Poda a seleção para os ids da resposta atual: um lançamento que saiu da lista (filtro, busca
  // ou categorizado por outro caminho) não volta marcado depois. Nunca com a resposta anterior
  // mantida por `keepPreviousData` (`isPlaceholderData`), que ainda é a lista velha. Só chama o
  // setState quando há o que podar, então não entra em loop.
  if (pendingQuery.data && !pendingQuery.isPlaceholderData) {
    const loadedIds = new Set(loaded.map((transaction) => transaction.id))
    if ([...selectedIds].some((id) => !loadedIds.has(id))) {
      setSelectedIds(new Set([...selectedIds].filter((id) => loadedIds.has(id))))
    }
  }

  const selected = loaded.filter((transaction) => selectedIds.has(transaction.id))

  const clearSelection = useCallback(() => setSelectedIds(new Set()), [])

  const toggleExpand = useCallback((key: string) => {
    setExpandedKeys((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }, [])

  const toggleTransaction = useCallback((id: number) => {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])

  const toggleGroup = useCallback((group: PendingCategorizationGroup) => {
    setSelectedIds((current) => toggleGroupSelection(current, group))
  }, [])

  const selectDirection = useCallback((group: PendingCategorizationGroup, groupDirection: Direction) => {
    setSelectedIds((current) => selectGroupDirection(current, group, groupDirection))
  }, [])

  const applySuggestion = useCallback(
    async (group: PendingCategorizationGroup) => {
      if (!group.suggestion) return
      const categoryId = group.suggestion.category_id
      const category = categoriesById.get(categoryId)
      const ids = group.transactions.map((transaction) => transaction.id)

      setApplyingGroupKey(group.key)
      try {
        const result = await applyCategorization({ transaction_ids: ids, category_id: categoryId })
        setSelectedIds((current) => {
          const groupIds = new Set(ids)
          const next = new Set([...current].filter((id) => !groupIds.has(id)))
          return next.size === current.size ? current : next
        })
        const count = result.categorized
        if (count === 0) {
          toast('Esses lançamentos já tinham categoria.')
        } else {
          const label = category ? categoryPath(category, categoriesById) : 'categoria'
          toast.success(`${count} ${pluralize(count, 'lançamento', 'lançamentos')} → ${label}`)
        }
      } catch (error) {
        notifyError(error)
      } finally {
        setApplyingGroupKey(null)
      }
    },
    [applyCategorization, categoriesById],
  )

  return (
    <>
      <PageHeader
        title="Categorizar"
        back
        subtitle={countQuery.data ? pendingCategorizationLabel(countQuery.data.total_pending, countQuery.data.group_count) : undefined}
      />
      {/* Com a barra de ação aberta (mais alta, com o bloco da regra), o fim da lista precisa de
          mais espaço para não ficar escondido atrás dela. */}
      <PageBody className={cn('space-y-3', selected.length > 0 ? 'pb-96' : 'pb-28')}>
        <div className="space-y-3 rounded-2xl border border-border bg-card p-3 shadow-card">
          {initialTotal !== null && (
            <div className="flex items-center gap-3 text-xs text-muted-foreground">
              <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${progressPercent}%` }} />
              </div>
              <span>
                {done} de {initialTotal} ({progressPercent}%)
              </span>
            </div>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-0 flex-1 basis-full sm:basis-auto">
              <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar grupo ou descrição…"
                aria-label="Buscar grupo ou descrição"
                className="pl-9"
              />
            </div>
            <ToggleGroup
              type="single"
              value={filterValue}
              onValueChange={(value) => value && setFilterValue(value as FilterValue)}
              aria-label="Filtrar por tipo"
              className="rounded-lg border border-input p-0.5"
            >
              <ToggleGroupItem value="all" size="sm" className="rounded-md data-[state=on]:bg-muted data-[state=on]:font-medium">
                Todos
              </ToggleGroupItem>
              <ToggleGroupItem value="out" size="sm" className="rounded-md data-[state=on]:bg-muted data-[state=on]:font-medium">
                Saídas
              </ToggleGroupItem>
              <ToggleGroupItem value="in" size="sm" className="rounded-md data-[state=on]:bg-muted data-[state=on]:font-medium">
                Entradas
              </ToggleGroupItem>
            </ToggleGroup>
          </div>
        </div>

        {pendingQuery.isError ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar os lançamentos pendentes."
              action={
                <Button variant="outline" onClick={() => pendingQuery.refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          </Card>
        ) : pendingQuery.isPending ? (
          <div className="space-y-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 w-full rounded-2xl" />
            ))}
          </div>
        ) : groups.length === 0 ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={filtered ? Tags : PartyPopper}
              title={filtered ? 'Nada encontrado' : 'Tudo categorizado'}
              description={filtered ? 'Ajuste a busca ou o filtro.' : 'Novos lançamentos sem categoria aparecem aqui e na Início.'}
            />
          </Card>
        ) : (
          <div
            aria-busy={pendingQuery.isPlaceholderData}
            className={cn('space-y-3 transition-opacity', pendingQuery.isPlaceholderData && 'opacity-60')}
          >
            {groups.map((group) => {
              const expanded = expandedKeys.has(group.key)
              return (
                <GroupCard
                  key={group.key}
                  group={group}
                  checkState={groupCheckState(group, selectedIds)}
                  selectedCount={group.transactions.filter((transaction) => selectedIds.has(transaction.id)).length}
                  selectedIds={expanded ? selectedIds : NO_SELECTION}
                  expanded={expanded}
                  suggestionLabel={suggestionLabelFor(group, categoriesById)}
                  applying={applyingGroupKey === group.key}
                  suggestionDisabled={applyPending}
                  onToggleExpand={toggleExpand}
                  onToggleGroup={toggleGroup}
                  onToggleTransaction={toggleTransaction}
                  onSelectDirection={selectDirection}
                  onApplySuggestion={applySuggestion}
                />
              )
            })}
          </div>
        )}
      </PageBody>

      {selected.length > 0 && (
        <CategorizeActionBar
          groups={groups}
          transactions={loaded}
          selected={selected}
          selectedIds={selectedIds}
          partialList={partialList}
          categoriesById={categoriesById}
          applyCategorization={applyCategorization}
          applyPending={applyPending}
          onClear={clearSelection}
          onApplied={clearSelection}
        />
      )}
    </>
  )
}

function suggestionLabelFor(group: PendingCategorizationGroup, categoriesById: ReadonlyMap<number, Category>): string | null {
  if (!group.suggestion) return null
  const category = categoriesById.get(group.suggestion.category_id)
  return category ? categoryPath(category, categoriesById) : null
}
