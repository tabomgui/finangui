import { Plus, Repeat, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useRecurrences } from '@/api/queries/recurrences'
import type { Recurrence } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { RecurrenceFormDialog } from './recurrence-form-dialog'
import { RecurrenceRow } from './recurrence-row'

export function RecurrencesPage() {
  const { data: recurrences, isPending, isError, refetch } = useRecurrences()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Recurrence | undefined>()

  const openCreate = () => {
    setEditing(undefined)
    setFormOpen(true)
  }

  const openEdit = (recurrence: Recurrence) => {
    setEditing(recurrence)
    setFormOpen(true)
  }

  return (
    <>
      <PageHeader
        title="Recorrências"
        subtitle="Lançamentos que se repetem"
        actions={
          <Button className={headerButton} onClick={openCreate}>
            <Plus className="h-4 w-4" />
            <span className="sr-only sm:not-sr-only">Nova recorrência</span>
          </Button>
        }
      />
      <PageBody>
        {isError && !recurrences ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar as recorrências."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        ) : isPending ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16 w-full rounded-xl" />
            ))}
          </div>
        ) : recurrences && recurrences.length > 0 ? (
          <Card className="rounded-2xl p-2 shadow-card">
            <ul className="divide-y divide-border">
              {recurrences.map((recurrence) => (
                <li key={recurrence.id}>
                  <RecurrenceRow recurrence={recurrence} onEdit={() => openEdit(recurrence)} />
                </li>
              ))}
            </ul>
          </Card>
        ) : (
          <EmptyState
            icon={Repeat}
            title="Nenhuma recorrência ainda"
            description="Aluguel, salário e assinaturas: cadastre uma vez e os lançamentos previstos aparecem sozinhos."
            action={<Button onClick={openCreate}>Criar recorrência</Button>}
          />
        )}
      </PageBody>

      <RecurrenceFormDialog open={formOpen} onOpenChange={setFormOpen} recurrence={editing} />
    </>
  )
}
