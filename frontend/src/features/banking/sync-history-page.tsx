import { History, RefreshCw, TriangleAlert } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useBankConnections, useSyncConnection } from '@/api/queries/bank-connections'
import { useBankSyncRuns } from '@/api/queries/bank-sync-runs'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { LoadMore } from '@/components/shared/load-more'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'
import { SyncRunDetailSheet } from './sync-run-detail-sheet'
import { SyncRunRow } from './sync-run-row'

/** Casca fina: só resolve o id da rota, mesmo padrão de `CardDetailPage` — a chave em
 * `connectionId` remonta o conteúdo ao trocar de conexão, sem estado (run selecionada, páginas
 * já carregadas) sobrevivendo de uma conexão para outra. */
export function SyncHistoryPage() {
  const connectionId = Number(useParams().id)
  return <SyncHistoryContent key={connectionId} connectionId={connectionId} />
}

function SyncHistoryContent({ connectionId }: { connectionId: number }) {
  // Sem GET /bank-connections/{id}: o nome do banco no cabeçalho vem da mesma lista usada em
  // Contas, já em cache na maioria das vezes (o link "Histórico" sai do card dessa lista). Sem
  // ela ainda carregada, o título principal já basta — nada aqui depende do nome para funcionar.
  const { data: connections } = useBankConnections()
  const connection = connections?.find((c) => c.id === connectionId)

  const { data, isPending, isError, error, refetch, hasNextPage, isFetchingNextPage, fetchNextPage } = useBankSyncRuns(connectionId)
  const sync = useSyncConnection()
  const [selectedId, setSelectedId] = useState<number | null>(null)

  const runs = data?.pages.flatMap((page) => page.data) ?? []
  const hasRunning = runs.some((run) => run.status === 'running')
  const syncDisabled = sync.isPending || hasRunning

  // 404: conexão de outro usuário ou inexistente (BelongsToUser + route model binding, ver
  // BankSyncRunController). Mesmo padrão de CardDetailPage: toast uma vez (ref, evita duplicar
  // sob StrictMode) + <Navigate replace /> de volta para a lista.
  const notFound = isError && error instanceof ApiError && error.status === 404
  const toastShown = useRef(false)
  useEffect(() => {
    if (notFound && !toastShown.current) {
      toastShown.current = true
      toast.error('Conexão não encontrada.')
    }
  }, [notFound])

  if (notFound) return <Navigate to="/contas" replace />

  async function handleSync() {
    try {
      await sync.mutateAsync(connectionId)
      toast.success('Sincronização iniciada.')
    } catch (err) {
      // Mesma mensagem de ConnectionCard para o 409 de sync já em andamento; 429 (throttle
      // bank-sync) já cai na mensagem padrão de notifyError/toApiError.
      if (err instanceof ApiError && err.code === 'connection_sync_in_progress') {
        toast.error('Sincronização em andamento.')
        return
      }
      notifyError(err)
    }
  }

  return (
    <>
      <PageHeader
        title="Histórico de sincronização"
        subtitle={connection?.institution_name ?? undefined}
        back="/contas"
        actions={
          <Button className={headerButton} onClick={handleSync} disabled={syncDisabled}>
            <RefreshCw className={cn('h-4 w-4', syncDisabled && 'animate-spin')} />
            <span className="sr-only sm:not-sr-only">Sincronizar agora</span>
          </Button>
        }
      />
      <PageBody>
        {isError ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar o histórico de sincronização."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        ) : isPending ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 w-full rounded-2xl" />
            ))}
          </div>
        ) : runs.length === 0 ? (
          <EmptyState
            icon={History}
            title="Nenhuma sincronização ainda"
            description="Sincronizações agendadas e manuais aparecem aqui conforme forem acontecendo."
          />
        ) : (
          <>
            <Card className="gap-0 overflow-clip rounded-2xl p-0 shadow-card">
              <ul className="divide-y divide-border">
                {runs.map((run) => (
                  <SyncRunRow key={run.id} run={run} onSelect={(selected) => setSelectedId(selected.id)} />
                ))}
              </ul>
            </Card>
            <LoadMore hasMore={Boolean(hasNextPage)} loading={isFetchingNextPage} onLoadMore={() => fetchNextPage()} />
          </>
        )}
      </PageBody>

      <SyncRunDetailSheet runId={selectedId} onOpenChange={(open) => !open && setSelectedId(null)} />
    </>
  )
}
