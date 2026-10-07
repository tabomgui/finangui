import { TriangleAlert } from 'lucide-react'
import { useBankSyncRun } from '@/api/queries/bank-sync-runs'
import { EmptyState } from '@/components/shared/empty-state'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/date'
import { cn } from '@/lib/utils'
import { addedAndUpdatedLabel, formatRunDateTime, TRIGGER_LABELS, warningOrErrorText } from './sync-run-labels'

type SyncRunDetailSheetProps = {
  /** `null` fecha a folha; o `Sheet` continua montado (ver `NotificationPanel`) para a animação
   * de saída não cortar no meio ao limpar a seleção. */
  runId: number | null
  onOpenChange: (open: boolean) => void
}

/** Detalhe de uma execução do histórico (`GET /bank-sync-runs/{id}`): os lançamentos adicionados,
 * com conta/data/descrição/valor, e uma nota quando a lista foi cortada (`items_truncated`). */
export function SyncRunDetailSheet({ runId, onOpenChange }: SyncRunDetailSheetProps) {
  const { data: run, isPending, isError, refetch } = useBankSyncRun(runId)
  const message = run ? warningOrErrorText(run) : null

  return (
    <Sheet open={runId !== null} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className="flex max-h-[85vh] flex-col rounded-t-2xl pb-[calc(1rem+env(safe-area-inset-bottom))]">
        <SheetHeader>
          <SheetTitle>{run ? formatRunDateTime(run.started_at) : 'Sincronização'}</SheetTitle>
        </SheetHeader>
        <div className="flex-1 overflow-y-auto px-4">
          {isError ? (
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar os detalhes."
              action={
                <Button variant="outline" onClick={() => refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          ) : isPending || !run ? (
            <div className="space-y-2 pb-4">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-14 w-full rounded-xl" />
              ))}
            </div>
          ) : (
            <div className="space-y-4 pb-4">
              <dl className="grid grid-cols-2 gap-2 text-sm">
                <div>
                  <dt className="text-xs text-muted-foreground">Gatilho</dt>
                  <dd>{TRIGGER_LABELS[run.trigger]}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted-foreground">Lançamentos</dt>
                  <dd>{addedAndUpdatedLabel(run)}</dd>
                </div>
              </dl>

              {message && (
                <p className={cn('text-sm', run.status === 'error' ? 'text-destructive' : 'text-amber-600 dark:text-amber-400')}>
                  {message}
                </p>
              )}

              <div>
                <h3 className="mb-2 text-sm font-medium">Lançamentos adicionados</h3>
                {run.items.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Nenhum lançamento adicionado nesta sincronização.</p>
                ) : (
                  <ul className="divide-y divide-border rounded-xl border border-border">
                    {run.items.map((item) => (
                      <li key={item.id} className="flex items-center gap-3 px-3 py-2">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{item.description}</p>
                          <p className="truncate text-xs text-muted-foreground">
                            {item.account_name} · {formatDate(item.date)}
                          </p>
                        </div>
                        <MoneyText cents={item.amount} direction={item.direction} className="shrink-0 text-sm font-semibold" />
                      </li>
                    ))}
                  </ul>
                )}
                {run.items_truncated && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    Mostrando só os primeiros {run.items.length} de {run.added_count} lançamentos adicionados.
                  </p>
                )}
              </div>
            </div>
          )}
        </div>
      </SheetContent>
    </Sheet>
  )
}
