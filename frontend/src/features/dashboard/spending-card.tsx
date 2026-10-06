import { ArrowLeft, ChartPie, TriangleAlert } from 'lucide-react'
import { lazy, Suspense, useId, useState } from 'react'
import { useSpendingBreakdown } from '@/api/queries/reports'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { monthRange } from '@/lib/date'
import { cn } from '@/lib/utils'
import { SpendingCategoryRow } from './spending-category-row'
import { SpendingLegend } from './spending-legend'
import { childViewEntries, entryTransactionFilters, rootViewEntries, type SpendingViewEntry } from './spending-shares'

// `.then` mantém o módulo com export nomeado (convenção do projeto: nunca `export default`) e
// ainda satisfaz o formato que `React.lazy` espera (ver `balance-hero.tsx`). O recharts só entra
// no bundle quando este card é de fato renderizado, nunca no chunk da Início.
const SpendingDonut = lazy(() => import('./spending-donut').then((m) => ({ default: m.SpendingDonut })))

type SpendingCardProps = { month: string }

/**
 * Card "Distribuição de gastos" da Início: donut + legenda + lista "Gastos por categoria" do mês
 * selecionado (independente do dia do saldo). Clicar numa categoria com subcategorias entra no
 * detalhamento ("Voltar" some do nível de topo); sem subcategorias, só destaca (esmaece as
 * outras). O destaque e o detalhamento são só desta tela: nenhuma regra de negócio aqui, tudo já
 * vem pronto de `GET /reports/spending`.
 */
export function SpendingCard({ month }: SpendingCardProps) {
  const { from, to } = monthRange(month)
  const { data, isPending, isError, isPlaceholderData, refetch } = useSpendingBreakdown(from, to)
  const listLabelId = useId()

  const [drillId, setDrillId] = useState<number | null>(null)
  const [highlightKey, setHighlightKey] = useState<string | null>(null)

  // Deriva o detalhamento ativo a partir dos dados atuais em vez de espelhar `drillId` num efeito:
  // se a categoria detalhada não existir mais nesta resposta (trocou o mês, ou deixou de ter gasto
  // depois de uma edição em outra aba), a tela já volta ao nível de topo sozinha, sem depender de
  // um reset explícito nem arriscar um detalhamento órfão.
  const drillCategory = drillId !== null ? data?.categories.find((category) => category.category_id === drillId) : undefined

  if (isError) {
    return (
      <Card className="rounded-2xl shadow-card">
        <CardHeader>
          <CardTitle className="text-base">
            <h2>Distribuição de gastos</h2>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar a distribuição de gastos."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        </CardContent>
      </Card>
    )
  }

  if (isPending || !data) {
    return (
      <Card className="rounded-2xl shadow-card">
        <CardHeader>
          <CardTitle className="text-base">
            <h2>Distribuição de gastos</h2>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <Skeleton className="mx-auto h-56 w-56 rounded-full" />
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-12 w-full rounded-xl" />
            ))}
          </div>
        </CardContent>
      </Card>
    )
  }

  const viewEntries = drillCategory ? childViewEntries(drillCategory.children, drillCategory.amount) : rootViewEntries(data.categories, data.total)
  const viewTotal = drillCategory ? drillCategory.amount : data.total
  const donutCaption = drillCategory ? drillCategory.name : 'Total do mês'
  const announcement = drillCategory ? `Mostrando subcategorias de ${drillCategory.name}` : 'Mostrando categorias do mês'

  const onEntryClick = (entry: SpendingViewEntry) => {
    if (!drillCategory && entry.hasChildren && entry.categoryId !== null) {
      setDrillId(entry.categoryId)
      setHighlightKey(null)
      return
    }
    setHighlightKey((current) => (current === entry.key ? null : entry.key))
  }

  const onBack = () => {
    setDrillId(null)
    setHighlightKey(null)
  }

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          <h2>Distribuição de gastos</h2>
        </CardTitle>
        {drillCategory && (
          <Button variant="ghost" size="sm" onClick={onBack}>
            <ArrowLeft className="h-4 w-4" />
            Voltar
          </Button>
        )}
      </CardHeader>
      <CardContent className={cn('space-y-4 transition-opacity', isPlaceholderData && 'opacity-60')} aria-busy={isPlaceholderData}>
        <p role="status" aria-live="polite" className="sr-only">
          {announcement}
        </p>
        {viewEntries.length === 0 ? (
          <EmptyState icon={ChartPie} title="Nenhuma despesa neste mês." />
        ) : (
          <>
            <Suspense fallback={<Skeleton className="mx-auto h-56 w-56 rounded-full" />}>
              <SpendingDonut
                entries={viewEntries}
                total={viewTotal}
                totalLabel={donutCaption}
                currency={data.currency}
                highlightKey={highlightKey}
                onSelect={onEntryClick}
              />
            </Suspense>
            <SpendingLegend entries={viewEntries} highlightKey={highlightKey} onSelect={onEntryClick} />
            <div>
              <p id={listLabelId} className="mb-1 px-1 text-sm font-medium text-muted-foreground">
                Gastos por categoria
              </p>
              <ul className="divide-y divide-border" aria-labelledby={listLabelId}>
                {viewEntries.map((entry) => (
                  <SpendingCategoryRow
                    key={entry.key}
                    entry={entry}
                    currency={data.currency}
                    filters={entryTransactionFilters(entry, { from, to, currency: data.currency, isChildLevel: Boolean(drillCategory) })}
                  />
                ))}
              </ul>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}
