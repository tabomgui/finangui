import { FileWarning, Inbox, TriangleAlert } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useCategories } from '@/api/queries/categories'
import { useCancelImport, useConfirmImport, useImportBatch } from '@/api/queries/imports'
import type { ImportOutcome, ImportPreviewRow as ImportRow } from '@/api/types'
import { groupByDay } from '@/features/transactions/group-by-day'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { formatDayLabel } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { ImportPreviewRow } from './import-preview-row'
import { BATCH_STATUS_LABELS, BATCH_STATUS_VARIANT, summaryText } from './import-labels'

// Mesmos desfechos marcados por padrão pela prévia (ver IngestionPlanner): `duplicate` nunca
// pode ser marcada, porque a linha não muda nada na confirmação de qualquer forma.
const SELECTABLE_OUTCOMES: readonly ImportOutcome[] = ['new', 'adopt', 'replace_installment', 'update', 'swap_pending']

function isSelectable(outcome: ImportOutcome): boolean {
  return SELECTABLE_OUTCOMES.includes(outcome)
}

// Renderizar as 5000 linhas de uma só vez deixaria a tela pesada; mostra em blocos.
const CHUNK_SIZE = 200

// Idem para "Linhas ignoradas": um arquivo ruim pode ter milhares de linhas inválidas.
const FAILED_CAP = 50

/** Mais recente primeiro; `line` como critério de desempate mantém a ordem do arquivo dentro do mesmo dia. */
function sortForDisplay(rows: ImportRow[]): ImportRow[] {
  return [...rows].sort((a, b) => (a.date === b.date ? a.line - b.line : a.date < b.date ? 1 : -1))
}

/** Casca fina: só resolve o id da rota. A chave em `id` remonta o conteúdo ao trocar de lote. */
export function ImportPreviewPage() {
  const id = Number(useParams().id)
  return <ImportPreviewContent key={id} id={id} />
}

function ImportPreviewContent({ id }: { id: number }) {
  const navigate = useNavigate()
  const { data: preview, isError, error, isPending, refetch } = useImportBatch(id)
  const { data: categories } = useCategories(true)
  const confirmImport = useConfirmImport()
  const cancelImport = useCancelImport()

  const [selectedLines, setSelectedLines] = useState<Set<number>>(() => new Set())
  const [initializedFor, setInitializedFor] = useState<number | null>(null)
  const [visibleCount, setVisibleCount] = useState(CHUNK_SIZE)

  const notFound = isError && error instanceof ApiError && error.status === 404
  const anyPending = confirmImport.isPending || cancelImport.isPending

  // Toast uma vez (ref) + <Navigate replace />, mesmo padrão de card-detail-page.tsx.
  const toastShown = useRef(false)
  useEffect(() => {
    if (notFound && !toastShown.current) {
      toastShown.current = true
      toast.error('Importação não encontrada.')
    }
  }, [notFound])

  // Marca por padrão as linhas selecionáveis assim que a prévia chega; roda uma vez por lote
  // (mesmo truque de "seenFiltersKey" em transactions-page.tsx, sem efeito extra).
  if (preview && initializedFor !== id) {
    setInitializedFor(id)
    setSelectedLines(new Set(preview.rows.filter((row) => isSelectable(row.outcome)).map((row) => row.line)))
  }

  const toggleLine = useCallback((line: number) => {
    setSelectedLines((current) => {
      const next = new Set(current)
      if (next.has(line)) next.delete(line)
      else next.add(line)
      return next
    })
  }, [])

  const categoryNames = useMemo(() => new Map((categories ?? []).map((category) => [category.id, category.name])), [categories])
  const rows = useMemo(() => preview?.rows ?? [], [preview?.rows])
  const selectableLines = useMemo(() => rows.filter((row) => isSelectable(row.outcome)).map((row) => row.line), [rows])
  const sortedRows = useMemo(() => sortForDisplay(rows), [rows])
  const selectedCount = selectableLines.filter((line) => selectedLines.has(line)).length
  const allSelected = selectableLines.length > 0 && selectedCount === selectableLines.length

  async function handleConfirm() {
    const skipLines = rows.filter((row) => isSelectable(row.outcome) && !selectedLines.has(row.line)).map((row) => row.line)
    try {
      await confirmImport.mutateAsync({ id, body: { skip_lines: skipLines } })
      toast.success('Importação concluída.')
      navigate('/importar')
    } catch (submitError) {
      notifyError(submitError)
      // 409 (lote já confirmado/revertido por outra aba): recarrega para mostrar o estado atual.
      if (submitError instanceof ApiError && submitError.status === 409) refetch()
    }
  }

  async function handleCancel() {
    try {
      await cancelImport.mutateAsync(id)
      navigate('/importar')
    } catch (cancelError) {
      notifyError(cancelError)
      if (cancelError instanceof ApiError && cancelError.status === 409) refetch()
    }
  }

  if (notFound) return <Navigate to="/importar" replace />

  if (isError && !preview) {
    return (
      <>
        <PageHeader title="Prévia da importação" back="/importar" />
        <PageBody>
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar a importação."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        </PageBody>
      </>
    )
  }

  if (isPending || !preview) return <FullPageSpinner />

  const { batch, summary } = preview

  if (batch.status !== 'pending') {
    return (
      <>
        <PageHeader title="Importação" back="/importar" />
        <PageBody>
          <Card className="space-y-3 rounded-2xl p-4 shadow-card">
            <Badge variant={BATCH_STATUS_VARIANT[batch.status]}>{BATCH_STATUS_LABELS[batch.status]}</Badge>
            <p className="text-sm text-muted-foreground">{summaryText(summary) || 'Nenhuma linha processada.'}</p>
            {batch.status === 'completed' && (
              <Button asChild>
                <Link to={`/transacoes?conta=${batch.account_id}`}>Ver lançamentos</Link>
              </Button>
            )}
          </Card>
        </PageBody>
      </>
    )
  }

  const visibleRows = sortedRows.slice(0, visibleCount)
  const hasMore = visibleCount < sortedRows.length
  const visibleFailed = batch.stats.failed.slice(0, FAILED_CAP)
  const hiddenFailedCount = batch.stats.failed.length - visibleFailed.length

  return (
    <>
      <PageHeader
        title="Prévia da importação"
        subtitle={`${batch.account?.name ?? 'Conta removida'} · ${batch.filename} · ${batch.format_label}`}
        back="/importar"
      />
      <PageBody className="pb-32">
        <Card className="rounded-2xl p-4 shadow-card">
          <p className="text-sm text-muted-foreground">{summaryText(summary) || 'Nenhuma linha para importar.'}</p>
        </Card>

        {selectableLines.length > 0 && (
          <div className="flex justify-end">
            <Button variant="ghost" size="sm" onClick={() => setSelectedLines(allSelected ? new Set() : new Set(selectableLines))}>
              {allSelected ? 'Desmarcar todas' : 'Marcar todas'}
            </Button>
          </div>
        )}

        <Card className="gap-0 overflow-clip rounded-2xl p-0 shadow-card">
          {rows.length === 0 ? (
            <EmptyState icon={Inbox} title="Nenhuma linha importável neste arquivo" />
          ) : (
            groupByDay(visibleRows).map((group, index) => (
              <section key={`${group.date}-${index}`} aria-label={formatDayLabel(group.date)}>
                <h2 className="bg-muted/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {formatDayLabel(group.date)}
                </h2>
                <ul className="divide-y divide-border">
                  {group.items.map((row) => (
                    <li key={row.line}>
                      <ImportPreviewRow
                        row={row}
                        selected={selectedLines.has(row.line)}
                        selectable={isSelectable(row.outcome)}
                        categoryName={row.suggested_category_id ? categoryNames.get(row.suggested_category_id) : undefined}
                        onToggle={toggleLine}
                      />
                    </li>
                  ))}
                </ul>
              </section>
            ))
          )}
        </Card>

        {hasMore && (
          <div className="flex justify-center">
            <Button variant="outline" onClick={() => setVisibleCount((count) => count + CHUNK_SIZE)}>
              Mostrar mais
            </Button>
          </div>
        )}

        {visibleFailed.length > 0 && (
          <Card className="space-y-2 rounded-2xl p-4 shadow-card">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
              <FileWarning className="h-4 w-4" />
              Linhas ignoradas
            </h2>
            <ul className="space-y-1 text-xs text-muted-foreground">
              {visibleFailed.map((failure) => (
                <li key={failure.line}>
                  Linha {failure.line}: {failure.reason}
                </li>
              ))}
            </ul>
            {hiddenFailedCount > 0 && <p className="text-xs text-muted-foreground">e mais {hiddenFailedCount} linhas.</p>}
          </Card>
        )}
      </PageBody>

      {/* Opaca e fixa acima da bottom nav do mobile (h-16 + safe-area, como bulk-action-bar.tsx);
          no desktop, sem bottom nav, só desloca pela sidebar fixa (w-60). */}
      <div className="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-40 px-4 md:bottom-6 md:pl-60">
        <div className="mx-auto flex max-w-3xl flex-wrap gap-2 rounded-2xl border border-border bg-card p-3 shadow-lg">
          <Button onClick={handleConfirm} disabled={anyPending || selectedCount === 0}>
            Importar {selectedCount} lançamento{selectedCount === 1 ? '' : 's'}
          </Button>
          <Button variant="outline" onClick={handleCancel} disabled={anyPending}>
            Cancelar
          </Button>
        </div>
      </div>
    </>
  )
}
