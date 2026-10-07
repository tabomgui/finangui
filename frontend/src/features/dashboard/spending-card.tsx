import { ArrowLeft, ChartPie, TriangleAlert } from 'lucide-react'
import { lazy, Suspense, useEffect, useId, useRef, useState, type ReactNode } from 'react'
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

type SpendingCardShellProps = {
  title: string
  onBack?: () => void
  backRef?: React.Ref<HTMLButtonElement>
  /** Sempre presente no DOM (mesmo vazio): só ganha texto depois de um detalhamento/"Voltar" de verdade, nunca ao abrir a tela. */
  announcement: string
  inert?: boolean
  children: ReactNode
}

/** Casco comum a todo estado do card (carregando, erro, vazio ou carregado) — título, "Voltar" opcional e a região viva. */
function SpendingCardShell({ title, onBack, backRef, announcement, inert = false, children }: SpendingCardShellProps) {
  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          <h2>{title}</h2>
        </CardTitle>
        {onBack && (
          <Button ref={backRef} variant="ghost" size="sm" onClick={onBack}>
            <ArrowLeft className="h-4 w-4" />
            Voltar
          </Button>
        )}
      </CardHeader>
      <CardContent
        className={cn('space-y-4 transition-opacity', inert && 'pointer-events-none opacity-60')}
        aria-busy={inert}
        inert={inert}
      >
        <p role="status" aria-live="polite" className="sr-only">
          {announcement}
        </p>
        {children}
      </CardContent>
    </Card>
  )
}

const TITLE = 'Distribuição de gastos'

/**
 * Card "Distribuição de gastos" da Início: donut + legenda + lista "Gastos por categoria" do mês
 * selecionado (independente do dia do saldo). Clicar numa categoria com subcategorias entra no
 * detalhamento ("Voltar" some do nível de topo); sem subcategorias, só destaca (esmaece as
 * outras). O destaque e o detalhamento são só desta tela: nenhuma regra de negócio aqui, tudo já
 * vem pronto de `GET /reports/spending`. Quem usa este componente monta com `key={month}` (ver
 * `dashboard-page.tsx`): trocar de mês é trocar de tela, não um estado a preservar.
 */
export function SpendingCard({ month }: SpendingCardProps) {
  const { from, to } = monthRange(month)
  const { data, isPending, isError, isPlaceholderData, refetch } = useSpendingBreakdown(from, to)
  const listLabelId = useId()
  const backRef = useRef<HTMLButtonElement>(null)
  const legendContainerRef = useRef<HTMLDivElement>(null)
  const returnFocusKeyRef = useRef<string | null>(null)

  const [drillId, setDrillId] = useState<number | null>(null)
  const [highlightKey, setHighlightKey] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState('')

  // Deriva o detalhamento ativo a partir dos dados atuais em vez de espelhar `drillId` num efeito:
  // se a categoria detalhada não existir mais nesta resposta, ou perdeu as subcategorias (deixou
  // de ter gasto direto/nas filhas depois de uma edição em outra aba, por exemplo), a tela já
  // volta ao nível de topo sozinha, sem depender de um reset explícito, de um detalhamento órfão
  // nem de um "Nenhuma despesa" enganoso enquanto a categoria em si ainda tem gasto.
  const drillCategory = drillId !== null
    ? data?.categories.find((category) => category.category_id === drillId && category.children.length > 0)
    : undefined

  // Depois de entrar no detalhamento, o foco vai para "Voltar" (o próximo passo natural);
  // depois de "Voltar", vai para o chip da categoria de onde veio (ver onBack/returnFocusKeyRef).
  useEffect(() => {
    if (drillId !== null) backRef.current?.focus()
  }, [drillId])

  useEffect(() => {
    if (drillId === null && returnFocusKeyRef.current) {
      const key = returnFocusKeyRef.current
      returnFocusKeyRef.current = null
      legendContainerRef.current?.querySelector<HTMLButtonElement>(`[data-chip-key="${key}"]`)?.focus()
    }
  }, [drillId])

  if (isError) {
    return (
      <SpendingCardShell title={TITLE} announcement="">
        <EmptyState
          icon={TriangleAlert}
          title="Não foi possível carregar a distribuição de gastos."
          action={
            <Button variant="outline" onClick={() => refetch()}>
              Tentar de novo
            </Button>
          }
        />
      </SpendingCardShell>
    )
  }

  if (isPending || !data) {
    return (
      <SpendingCardShell title={TITLE} announcement="">
        <Skeleton data-testid="spending-donut-skeleton" className="mx-auto h-56 w-56 rounded-full" />
        <div className="space-y-2">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} data-testid="spending-row-skeleton" className="h-12 w-full rounded-xl" />
          ))}
        </div>
      </SpendingCardShell>
    )
  }

  const viewEntries = drillCategory ? childViewEntries(drillCategory.children, drillCategory.amount) : rootViewEntries(data.categories, data.total)
  const viewTotal = drillCategory ? drillCategory.amount : data.total
  const donutCaption = drillCategory ? drillCategory.name : 'Total do mês'
  const title = drillCategory ? `${TITLE} · ${drillCategory.name}` : TITLE
  const listLabel = drillCategory ? `Subcategorias de ${drillCategory.name}` : 'Gastos por categoria'
  // Uma chave que não existe mais nesta tela (categoria renomeada/sem gasto depois de uma edição
  // em outra aba) não esmaece todo mundo por engano: sem correspondência, ninguém fica destacado.
  const activeHighlightKey = viewEntries.some((entry) => entry.key === highlightKey) ? highlightKey : null

  const onEntryClick = (entry: SpendingViewEntry) => {
    if (!drillCategory && entry.hasChildren && entry.categoryId !== null) {
      setDrillId(entry.categoryId)
      setHighlightKey(null)
      setAnnouncement(`Mostrando subcategorias de ${entry.name}`)
      return
    }
    setHighlightKey((current) => (current === entry.key ? null : entry.key))
  }

  const onBack = () => {
    returnFocusKeyRef.current = drillId !== null ? String(drillId) : null
    setDrillId(null)
    setHighlightKey(null)
    setAnnouncement('Mostrando categorias do mês')
  }

  return (
    <SpendingCardShell title={title} onBack={drillCategory ? onBack : undefined} backRef={backRef} announcement={announcement} inert={isPlaceholderData}>
      {viewEntries.length === 0 ? (
        <EmptyState icon={ChartPie} title="Nenhuma despesa neste mês." />
      ) : (
        <>
          <Suspense fallback={<Skeleton data-testid="spending-donut-skeleton" className="mx-auto h-56 w-56 rounded-full" />}>
            <SpendingDonut
              entries={viewEntries}
              total={viewTotal}
              totalLabel={donutCaption}
              currency={data.currency}
              highlightKey={activeHighlightKey}
              onSelect={onEntryClick}
            />
          </Suspense>
          <div ref={legendContainerRef}>
            <SpendingLegend entries={viewEntries} highlightKey={activeHighlightKey} onSelect={onEntryClick} />
          </div>
          <div>
            <p id={listLabelId} className="mb-1 px-1 text-sm font-medium text-muted-foreground">
              {listLabel}
            </p>
            <ul className="divide-y divide-border" aria-labelledby={listLabelId} data-testid="spending-category-list">
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
    </SpendingCardShell>
  )
}
