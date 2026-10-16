import { PartyPopper, Search, Tags, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useApplyCategorization, usePendingCategorization, usePendingCategorizationCount } from '@/api/queries/categorization'
import { useCategories } from '@/api/queries/categories'
import type { Direction, PendingCategorizationGroup } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { notifyError } from '@/lib/form-errors'
import { GroupCard } from './group-card'
import { pendingCategorizationLabel } from './pending-label'
import { groupCheckState, selectGroupDirection, toggleGroupSelection } from './selection'

type FilterValue = 'all' | 'out' | 'in'

export function CategorizePage() {
  const [search, setSearch] = useState('')
  const [filterValue, setFilterValue] = useState<FilterValue>('all')
  const [expandedKeys, setExpandedKeys] = useState<Set<string>>(new Set())
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const [applyingGroupKey, setApplyingGroupKey] = useState<string | null>(null)
  // Baseline da barra de progresso: capturado na primeira resposta e nunca mais tocado por
  // filtro/busca — só avança quando lançamentos são de fato categorizados (ver `done` abaixo).
  const [initialTotal, setInitialTotal] = useState<number | null>(null)

  const countQuery = usePendingCategorizationCount()
  const direction: Direction | undefined = filterValue === 'all' ? undefined : filterValue
  const debouncedSearch = useDebouncedValue(search, 350)
  const pendingQuery = usePendingCategorization({ direction, search: debouncedSearch.trim() || undefined })
  const { data: categories = [] } = useCategories()
  const applyCategorization = useApplyCategorization()

  if (initialTotal === null && countQuery.data) setInitialTotal(countQuery.data.total_pending)

  const totalPending = countQuery.data?.total_pending ?? 0
  const done = initialTotal === null ? 0 : Math.max(initialTotal - totalPending, 0)
  const progressTotal = initialTotal ?? totalPending
  const progressPercent = progressTotal > 0 ? Math.round((done / progressTotal) * 100) : 100

  const groups = pendingQuery.data?.groups ?? []
  const filtered = search.trim() !== '' || filterValue !== 'all'

  const toggleExpand = (key: string) => {
    setExpandedKeys((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const toggleTransaction = (id: number) => {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const toggleGroup = (group: PendingCategorizationGroup) => {
    setSelectedIds((current) => toggleGroupSelection(current, group))
  }

  const selectDirection = (group: PendingCategorizationGroup, groupDirection: Direction) => {
    setSelectedIds((current) => selectGroupDirection(current, group, groupDirection))
  }

  const applySuggestion = async (group: PendingCategorizationGroup) => {
    if (!group.suggestion) return
    const categoryId = group.suggestion.category_id
    const category = categories.find((each) => each.id === categoryId)
    const ids = group.transactions.map((transaction) => transaction.id)

    setApplyingGroupKey(group.key)
    try {
      const result = await applyCategorization.mutateAsync({ transaction_ids: ids, category_id: categoryId })
      setSelectedIds((current) => {
        const groupIds = new Set(ids)
        const next = new Set([...current].filter((id) => !groupIds.has(id)))
        return next.size === current.size ? current : next
      })
      const count = result.categorized
      toast.success(`${count} ${count === 1 ? 'lançamento' : 'lançamentos'} → ${category?.name ?? 'categoria'}`)
    } catch (error) {
      notifyError(error)
    } finally {
      setApplyingGroupKey(null)
    }
  }

  return (
    <>
      <PageHeader
        title="Categorizar"
        back
        subtitle={countQuery.data ? pendingCategorizationLabel(countQuery.data.total_pending, countQuery.data.group_count) : undefined}
      />
      <PageBody className="space-y-3 pb-28">
        <div className="space-y-3 rounded-2xl border border-border bg-card p-3 shadow-card">
          <div className="flex items-center gap-3 text-xs text-muted-foreground">
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
              <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${progressPercent}%` }} />
            </div>
            <span>
              {done} de {progressTotal} ({progressPercent}%)
            </span>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-0 flex-1">
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
          groups.map((group) => (
            <GroupCard
              key={group.key}
              group={group}
              checkState={groupCheckState(group, selectedIds)}
              selectedIds={selectedIds}
              expanded={expandedKeys.has(group.key)}
              suggestionCategory={group.suggestion ? (categories.find((each) => each.id === group.suggestion?.category_id) ?? null) : null}
              applying={applyingGroupKey === group.key && applyCategorization.isPending}
              onToggleExpand={() => toggleExpand(group.key)}
              onToggleGroup={() => toggleGroup(group)}
              onToggleTransaction={toggleTransaction}
              onSelectDirection={(groupDirection) => selectDirection(group, groupDirection)}
              onApplySuggestion={() => applySuggestion(group)}
            />
          ))
        )}
      </PageBody>

      {selectedIds.size > 0 && (
        <div className="fixed inset-x-0 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-40 px-4 md:bottom-6 md:pl-60">
          <div className="mx-auto flex max-w-3xl items-center gap-2 rounded-2xl border border-border bg-card p-3 shadow-lg">
            <span className="flex-1 text-sm font-medium">
              {selectedIds.size} selecionado{selectedIds.size === 1 ? '' : 's'}
            </span>
            <Button type="button" variant="ghost" size="sm" onClick={() => setSelectedIds(new Set())}>
              Limpar
            </Button>
          </div>
        </div>
      )}
    </>
  )
}
