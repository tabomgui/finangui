import { ChartColumn, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useCategoryComparison, useMonthlyReport } from '@/api/queries/reports'
import type { ReportBasis } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { monthKey, shiftMonth } from '@/lib/date'
import { cn } from '@/lib/utils'
import { monthFromParam } from '../dashboard/shares'
import { CategoryComparisonTable } from './category-comparison-table'
import { ComparisonPeriodPicker } from './comparison-period-picker'
import { EvolutionChart } from './evolution-chart'
import { evolutionRange, monthAxisLabel, type EvolutionRangeKey } from './period'
import { ReportFilters } from './report-filters'

function rangeFromParam(value: string | null): EvolutionRangeKey {
  return value === 'last6' || value === 'last12' || value === 'year' ? value : 'last12'
}

function basisFromParam(value: string | null): ReportBasis {
  return value === 'statement' ? 'statement' : 'purchase'
}

export function ReportsPage() {
  const [params, setParams] = useSearchParams()
  // `new Date()` só na montagem: evita recalcular "hoje" a cada render (ver dashboard-page.tsx).
  const [currentMonth] = useState(() => monthKey(new Date()))
  const range = rangeFromParam(params.get('periodo'))
  const basis = basisFromParam(params.get('base'))
  const monthA = monthFromParam(params.get('mesA'), shiftMonth(currentMonth, -1))
  const monthB = monthFromParam(params.get('mesB'), currentMonth)

  const setParam = (key: string, value: string) =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        next.set(key, value)
        return next
      },
      { replace: true },
    )

  const { from, to } = evolutionRange(range, currentMonth)
  const evolution = useMonthlyReport(from, to, basis)
  const comparison = useCategoryComparison(monthA, monthA, monthB, monthB, basis)

  return (
    <>
      <PageHeader title="Relatórios" subtitle="Evolução mensal e comparação por categoria" />
      <PageBody>
        <ReportFilters
          range={range}
          onRangeChange={(value) => setParam('periodo', value)}
          basis={basis}
          onBasisChange={(value) => setParam('base', value)}
        />

        <Card className="rounded-2xl shadow-card">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Evolução mensal</CardTitle>
          </CardHeader>
          <CardContent>
            {evolution.isError && !evolution.data ? (
              <EmptyState
                icon={TriangleAlert}
                title="Não foi possível carregar a evolução mensal."
                action={
                  <Button variant="outline" onClick={() => evolution.refetch()}>
                    Tentar de novo
                  </Button>
                }
              />
            ) : evolution.isPending || !evolution.data ? (
              <Skeleton className="h-72 w-full rounded-xl" />
            ) : evolution.data.months.every((month) => month.income === 0 && month.expense === 0) ? (
              <EmptyState icon={ChartColumn} title="Nenhuma movimentação no período" />
            ) : (
              <div
                className={cn('transition-opacity', evolution.isPlaceholderData && 'opacity-60')}
                aria-busy={evolution.isPlaceholderData}
              >
                <EvolutionChart months={evolution.data.months} currency={evolution.data.currency} />
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="rounded-2xl shadow-card">
          <CardHeader>
            <CardTitle>Comparação por categoria</CardTitle>
          </CardHeader>
          {/* `min-w-0`: CardContent é item de um flex column (Card); sem isso, a tabela de
              comparação (min-w fixo) empurraria o card — e a página — para além da viewport
              em vez de rolar só dentro do próprio `overflow-x-auto`. */}
          <CardContent className="min-w-0 space-y-4">
            <ComparisonPeriodPicker
              monthA={monthA}
              monthB={monthB}
              onChangeA={(value) => setParam('mesA', value)}
              onChangeB={(value) => setParam('mesB', value)}
            />

            {comparison.isError && !comparison.data ? (
              <EmptyState
                icon={TriangleAlert}
                title="Não foi possível carregar a comparação por categoria."
                action={
                  <Button variant="outline" onClick={() => comparison.refetch()}>
                    Tentar de novo
                  </Button>
                }
              />
            ) : comparison.isPending || !comparison.data ? (
              <div className="space-y-2">
                {[0, 1, 2].map((i) => (
                  <Skeleton key={i} className="h-10 w-full rounded-lg" />
                ))}
              </div>
            ) : comparison.data.items.length === 0 ? (
              <EmptyState icon={ChartColumn} title="Nenhuma despesa nos períodos selecionados" />
            ) : (
              <div
                className={cn('transition-opacity', comparison.isPlaceholderData && 'opacity-60')}
                aria-busy={comparison.isPlaceholderData}
              >
                <CategoryComparisonTable
                  items={comparison.data.items}
                  totals={comparison.data.totals}
                  currency={comparison.data.currency}
                  labelA={monthAxisLabel(monthA)}
                  labelB={monthAxisLabel(monthB)}
                />
              </div>
            )}
          </CardContent>
        </Card>
      </PageBody>
    </>
  )
}
