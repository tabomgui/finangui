import { closestCenter, DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Plus, TriangleAlert, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { useCategories } from '@/api/queries/categories'
import { useDeleteRule, useReorderRules, useRules, useUpdateRule } from '@/api/queries/rules'
import { useTags } from '@/api/queries/tags'
import type { Rule } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { notifyError } from '@/lib/form-errors'
import { reorderIds, type RuleLookups } from './rule-labels'
import { SortableRuleRow } from './sortable-rule-row'

export function RulesPage() {
  const { data: rules, isPending, isError, refetch } = useRules()
  const { data: categories } = useCategories(true)
  const { data: tags } = useTags()
  const update = useUpdateRule()
  const remove = useDeleteRule()
  const reorder = useReorderRules()
  const [deleting, setDeleting] = useState<Rule | null>(null)

  const lookups: RuleLookups = {
    categoryName: (id) => categories?.find((category) => category.id === id)?.name,
    tagName: (id) => tags?.find((tag) => tag.id === id)?.name,
  }

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const toggleActive = async (rule: Rule, isActive: boolean) => {
    try {
      await update.mutateAsync({ id: rule.id, body: { is_active: isActive } })
    } catch (error) {
      notifyError(error)
    }
  }

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!rules || !over || active.id === over.id) return
    const ids = rules.map((rule) => rule.id)
    reorder.mutate(reorderIds(ids, Number(active.id), Number(over.id)))
  }

  return (
    <>
      <PageHeader
        title="Regras"
        subtitle="Categorização automática"
        actions={
          <Button asChild className={headerButton}>
            <Link to="/regras/nova">
              <Plus className="h-4 w-4" />
              Nova regra
            </Link>
          </Button>
        }
      />
      <PageBody>
        {isError ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar as regras."
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
        ) : rules && rules.length > 0 ? (
          <>
            <Card className="rounded-2xl p-2 shadow-card">
              <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={rules.map((rule) => rule.id)} strategy={verticalListSortingStrategy}>
                  <ul className="divide-y divide-border">
                    {rules.map((rule) => (
                      <SortableRuleRow
                        key={rule.id}
                        rule={rule}
                        lookups={lookups}
                        onToggleActive={toggleActive}
                        onDelete={setDeleting}
                      />
                    ))}
                  </ul>
                </SortableContext>
              </DndContext>
            </Card>
            <p className="px-1 text-xs text-muted-foreground">
              As regras rodam de cima para baixo. Para categoria, descrição e favorecido vale a primeira que casar.
            </p>
          </>
        ) : (
          <EmptyState
            icon={Wand2}
            title="Nenhuma regra ainda"
            description="Regras categorizam lançamentos novos automaticamente e também podem ser aplicadas aos já existentes."
            action={
              <Button asChild>
                <Link to="/regras/nova">Criar regra</Link>
              </Button>
            }
          />
        )}
      </PageBody>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Excluir ${deleting?.name ?? 'regra'}?`}
        description="Os lançamentos já categorizados por ela continuam como estão."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          if (deleting) await remove.mutateAsync(deleting.id)
          toast.success('Regra excluída.')
        }}
      />
    </>
  )
}
