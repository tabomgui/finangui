import { FileWarning, Inbox, TriangleAlert } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useCategories } from '@/api/queries/categories'
import { useCancelImport, useConfirmImport, useImportBatch } from '@/api/queries/imports'
import type { ImportOutcome } from '@/api/types'
import { groupByDay } from '@/features/transactions/group-by-day'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { formatDayLabel } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { ImportPreviewRow } from './import-preview-row'
import { statsSummary, summaryText } from './import-labels'

// Mesmos desfechos marcados por padrão pela prévia (ver IngestionPlanner): `duplicate` nunca
// pode ser marcada, porque a linha não muda nada na confirmação de qualquer forma.
const SELECTABLE_OUTCOMES: readonly ImportOutcome[] = ['new', 'adopt', 'replace_installment', 'update', 'swap_pending']

function isSelectable(outcome: ImportOutcome): boolean {
  return SELECTABLE_OUTCOMES.includes(outcome)
}

// Renderizar as 5000 linhas de uma vez de uma só vez deixaria a tela pesada; mostra em blocos.
const CHUNK_SIZE = 200

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

  const { batch, rows, summary } = preview

  if (batch.status !== 'pending') {
    return (
      <>
        <PageHeader title="Prévia da importação" back="/importar" />
        <PageBody>
          <Card className="space-y-3 rounded-2xl p-4 shadow-card">
            <p className="text-sm text-muted-foreground">
              {summaryText(statsSummary(batch.stats)) || 'Nenhuma linha processada.'}
            </p>
            <Button asChild>
              <Link to={`/transacoes?conta=${batch.account_id}`}>Ver lançamentos</Link>
            </Button>
          </Card>
        </PageBody>
      </>
    )
  }

  const categoryNames = new Map((categories ?? []).map((category) => [category.id, category.name]))
  const selectableLines = rows.filter((row) => isSelectable(row.outcome)).map((row) => row.line)
  const allSelected = selectableLines.length > 0 && selectableLines.every((line) => selectedLines.has(line))
  const visibleRows = rows.slice(0, visibleCount)
  const hasMore = visibleCount < rows.length

  function toggleLine(line: number) {
    setSelectedLines((current) => {
      const next = new Set(current)
      if (next.has(line)) next.delete(line)
      else next.add(line)
      return next
    })
  }

  async function handleConfirm() {
    const skipLines = rows.filter((row) => !selectedLines.has(row.line)).map((row) => row.line)
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
    }
  }

  return (
    <>
      <PageHeader
        title="Prévia da importação"
        subtitle={`${batch.account?.name ?? 'Conta removida'} · ${batch.filename} · ${batch.format_label}`}
        back="/importar"
      />
      <PageBody>
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
            groupByDay(visibleRows).map((group) => (
              <section key={group.date} aria-label={formatDayLabel(group.date)}>
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

        {batch.stats.failed.length > 0 && (
          <Card className="space-y-2 rounded-2xl p-4 shadow-card">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
              <FileWarning className="h-4 w-4" />
              Linhas ignoradas
            </h2>
            <ul className="space-y-1 text-xs text-muted-foreground">
              {batch.stats.failed.map((failure) => (
                <li key={failure.line}>
                  Linha {failure.line}: {failure.reason}
                </li>
              ))}
            </ul>
          </Card>
        )}

        <div className="flex flex-wrap gap-2">
          <Button onClick={handleConfirm} disabled={confirmImport.isPending}>
            Importar {selectedLines.size} lançamento{selectedLines.size === 1 ? '' : 's'}
          </Button>
          <Button variant="outline" onClick={handleCancel} disabled={cancelImport.isPending}>
            Cancelar
          </Button>
        </div>
      </PageBody>
    </>
  )
}
