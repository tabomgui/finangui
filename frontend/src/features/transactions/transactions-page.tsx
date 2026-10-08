import { ArrowRight, List, Plus, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTransferSuggestions } from '@/api/queries/transfer-suggestions'
import { useTransactions } from '@/api/queries/transactions'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { LoadMore } from '@/components/shared/load-more'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'
import { formatDayLabel } from '@/lib/date'
import { BulkActionBar } from './bulk-action-bar'
import { activeFilterCount, effectiveTransactionFilters, filtersFromParams, paramsWithFuture, showFutureFromParams } from './filters'
import { groupByDay } from './group-by-day'
import { TransactionFilters } from './transaction-filters'
import { TransactionRow } from './transaction-row'
import { firstPageSuggestionsCount, suggestionsCountLabel } from '../transfers/suggestions-count'

export function TransactionsPage() {
  const [params, setParams] = useSearchParams()
  const filters = filtersFromParams(params)
  const showFuture = showFutureFromParams(params)
  const query = useTransactions(effectiveTransactionFilters(filters, showFuture))
  const transactions = query.data?.pages.flatMap((page) => page.data) ?? []
  const filtered = activeFilterCount(filters) > 0 || filters.search !== undefined

  const pendingSuggestions = firstPageSuggestionsCount(useTransferSuggestions().data?.pages[0])

  const [selecting, setSelecting] = useState(false)
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const selected = transactions.filter((transaction) => selectedIds.has(transaction.id))

  // Os filtros mudam a lista: ids selecionados que não aparecem mais não devem ficar presos na seleção.
  // Comparar com a última chave de filtros vista durante o render evita um efeito extra (mesmo truque de transaction-filters.tsx).
  const filtersKey = JSON.stringify(filters)
  const [seenFiltersKey, setSeenFiltersKey] = useState(filtersKey)
  if (filtersKey !== seenFiltersKey) {
    setSeenFiltersKey(filtersKey)
    setSelectedIds(new Set())
  }

  const toggleSelected = (id: number) => {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  return (
    <>
      <PageHeader
        title="Transações"
        subtitle="Receitas, despesas e transferências"
        actions={
          <Button asChild className={`${headerButton} hidden md:inline-flex`}>
            <Link to="/transacoes/nova">
              <Plus className="h-4 w-4" />
              Nova
            </Link>
          </Button>
        }
      />
      <PageBody>
        {pendingSuggestions.count > 0 && (
          <Link
            to="/transferencias/sugestoes"
            // bg-muted/hover:bg-accent, nunca uma variação com opacidade (/NN): esta superfície
            // fica no topo do PageBody, sobre a faixa esmeralda do PageHeader — translúcida no
            // hover deixaria a faixa aparecer atrás (ver CLAUDE.md).
            className="flex items-center justify-between gap-2 rounded-xl bg-muted px-4 py-2 text-sm font-medium hover:bg-accent"
          >
            <span>{suggestionsCountLabel(pendingSuggestions)}</span>
            <ArrowRight className="h-4 w-4" />
          </Link>
        )}
        <TransactionFilters filters={filters} />
        {!query.isError && transactions.length > 0 && (
          <div className="flex justify-end">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSelecting((value) => !value)
                setSelectedIds(new Set())
              }}
            >
              {selecting ? 'Cancelar seleção' : 'Selecionar'}
            </Button>
          </div>
        )}
        {query.isError ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar as transações."
              action={
                <Button variant="outline" onClick={() => query.refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          </Card>
        ) : (
          <Card
            aria-busy={query.isPlaceholderData}
            className={cn('gap-0 overflow-clip rounded-2xl p-0 shadow-card transition-opacity', query.isPlaceholderData && 'opacity-60')}
          >
            {query.isPending ? (
              <div className="space-y-2 p-3">
                {[0, 1, 2, 3, 4].map((i) => (
                  <Skeleton key={i} className="h-14 w-full rounded-xl" />
                ))}
              </div>
            ) : transactions.length === 0 ? (
              <EmptyState
                icon={List}
                title={filtered ? 'Nada encontrado' : 'Nenhuma transação ainda'}
                description={filtered ? 'Ajuste os filtros ou a busca.' : 'Registre seu primeiro lançamento.'}
                action={
                  filtered ? undefined : (
                    <Button asChild>
                      <Link to="/transacoes/nova">Nova transação</Link>
                    </Button>
                  )
                }
              />
            ) : (
              groupByDay(transactions).map((group) => (
                <section key={group.date} aria-label={formatDayLabel(group.date)}>
                  <h2 className="sticky top-[env(safe-area-inset-top)] z-10 bg-muted/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground backdrop-blur">
                    {formatDayLabel(group.date)}
                  </h2>
                  <ul className="divide-y divide-border">
                    {group.items.map((transaction) => (
                      <li key={transaction.id}>
                        <TransactionRow
                          transaction={transaction}
                          selectable={selecting}
                          selected={selectedIds.has(transaction.id)}
                          onToggle={toggleSelected}
                        />
                      </li>
                    ))}
                  </ul>
                </section>
              ))
            )}
          </Card>
        )}
        <LoadMore
          hasMore={Boolean(query.hasNextPage) && !query.isPlaceholderData}
          loading={query.isFetchingNextPage}
          onLoadMore={() => query.fetchNextPage()}
        />
        <div className="flex items-center justify-end gap-2 px-1">
          <Switch
            id="show-future-transactions"
            checked={showFuture}
            onCheckedChange={(checked) => setParams((current) => paramsWithFuture(current, checked), { replace: true })}
          />
          <Label htmlFor="show-future-transactions" className="text-sm text-muted-foreground">
            Mostrar lançamentos futuros
          </Label>
        </div>
      </PageBody>
      {selecting && selected.length > 0 && (
        <BulkActionBar
          selected={selected}
          onDone={() => {
            setSelecting(false)
            setSelectedIds(new Set())
          }}
        />
      )}
    </>
  )
}
