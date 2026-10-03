import { format, parseISO } from 'date-fns'
import { EllipsisVertical, FileClock, RotateCcw, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { useImportBatches, useRevertImport } from '@/api/queries/imports'
import type { ImportBatch, ImportBatchStatus } from '@/api/types'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { BATCH_STATUS_LABELS, statsSummary, summaryText } from './import-labels'

function formatBatchDate(value: string): string {
  return format(parseISO(value), "dd/MM/yyyy 'às' HH:mm")
}

const STATUS_VARIANT: Record<ImportBatchStatus, 'secondary' | 'default' | 'outline'> = {
  pending: 'secondary',
  completed: 'default',
  reverted: 'outline',
}

export function ImportHistoryCard() {
  const { data: batches, isPending, isError, refetch } = useImportBatches()
  const revert = useRevertImport()
  const [reverting, setReverting] = useState<ImportBatch | null>(null)

  return (
    <Card className="space-y-2 rounded-2xl p-2 shadow-card">
      {isError ? (
        <EmptyState
          icon={TriangleAlert}
          title="Não foi possível carregar o histórico de importações."
          action={
            <Button variant="outline" onClick={() => refetch()}>
              Tentar de novo
            </Button>
          }
        />
      ) : isPending ? (
        <div className="space-y-2 p-2">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-16 w-full rounded-xl" />
          ))}
        </div>
      ) : batches && batches.length > 0 ? (
        <ul className="divide-y divide-border">
          {batches.map((batch) => (
            <li key={batch.id} className="flex items-center gap-3 px-3 py-3">
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 truncate font-medium">
                  <span className="truncate">{batch.account?.name ?? 'Conta removida'}</span>
                  <Badge variant={STATUS_VARIANT[batch.status]}>{BATCH_STATUS_LABELS[batch.status]}</Badge>
                </p>
                <p className="truncate text-xs text-muted-foreground">
                  {batch.format_label} · {batch.filename} · {formatBatchDate(batch.created_at)}
                </p>
                {batch.status !== 'pending' && (
                  <p className="truncate text-xs text-muted-foreground">
                    {summaryText(statsSummary(batch.stats)) || 'Nenhuma linha processada.'}
                  </p>
                )}
              </div>
              {batch.status === 'pending' ? (
                <Button asChild variant="outline" size="sm">
                  <Link to={`/importar/${batch.id}`}>Continuar</Link>
                </Button>
              ) : batch.status === 'completed' ? (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label={`Ações da importação de ${batch.filename}`}>
                      <EllipsisVertical className="h-4 w-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setReverting(batch)}>
                      <RotateCcw className="h-4 w-4" />
                      Reverter
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              ) : null}
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState icon={FileClock} title="Nenhuma importação ainda" />
      )}

      <ConfirmDialog
        open={reverting !== null}
        onOpenChange={(open) => !open && setReverting(null)}
        title="Reverter importação?"
        description="Os lançamentos importados serão excluídos e os lançamentos manuais casados voltam ao que eram."
        confirmLabel="Reverter"
        destructive
        onConfirm={async () => {
          if (!reverting) return
          await revert.mutateAsync(reverting.id)
          toast.success('Importação revertida.')
        }}
      />
    </Card>
  )
}
