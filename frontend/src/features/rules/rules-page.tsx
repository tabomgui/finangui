import { type Announcements, closestCenter, DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Plus, TriangleAlert, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { useAccounts } from '@/api/queries/accounts'
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
import type { RuleLookups } from './rule-labels'
import { reorderIds } from './reorder'
import { SortableRuleRow } from './sortable-rule-row'

// Instrução lida uma vez por quem navega pelo teclado com leitor de tela; "announcements" abaixo
// narra cada passo do arrasto (também usado pelo `KeyboardSensor`, que arrasta sem mouse).
const screenReaderInstructions = {
  draggable:
    'Para reordenar uma regra, pressione espaço ou enter. Use as setas para cima e para baixo para mover a regra na lista. Pressione espaço ou enter de novo para confirmar a nova posição, ou esc para cancelar.',
}

export function RulesPage() {
  const { data: rules, isPending, isError, refetch } = useRules()
  const { data: categories } = useCategories(true)
  const { data: tags } = useTags()
  const { data: accounts } = useAccounts(true)
  const update = useUpdateRule()
  const remove = useDeleteRule()
  const reorder = useReorderRules()
  const [deleting, setDeleting] = useState<Rule | null>(null)

  const lookups: RuleLookups = {
    categoryName: (id) => {
      const category = categories?.find((item) => item.id === id)
      if (!category) return undefined
      return category.is_archived ? `${category.name} (arquivada)` : category.name
    },
    tagName: (id) => tags?.find((tag) => tag.id === id)?.name,
    accountName: (id) => accounts?.find((account) => account.id === id)?.name,
    loading: categories === undefined || tags === undefined || accounts === undefined,
  }

  // Só a regra com a troca do switch "ativa" em voo fica desabilitada — as outras continuam
  // respondendo normalmente enquanto essa requisição não termina.
  const pendingRuleId = update.isPending ? update.variables?.id : undefined

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

  const ruleName = (id: number | string) => rules?.find((rule) => rule.id === id)?.name ?? ''
  const positionOf = (id: number | string) => (rules?.findIndex((rule) => rule.id === id) ?? -1) + 1

  const announcements: Announcements = {
    onDragStart: ({ active }) => `Pegou a regra ${ruleName(active.id)}.`,
    onDragOver: ({ active, over }) =>
      over && rules ? `Regra ${ruleName(active.id)} movida para a posição ${positionOf(over.id)} de ${rules.length}.` : undefined,
    onDragEnd: ({ active, over }) =>
      over && rules
        ? `Regra ${ruleName(active.id)} movida para a posição ${positionOf(over.id)} de ${rules.length}.`
        : `Soltou a regra ${ruleName(active.id)} sem mudar a posição.`,
    onDragCancel: ({ active }) => `Reordenação da regra ${ruleName(active.id)} cancelada.`,
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
        {isError && !rules ? (
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
              <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
                accessibility={{ screenReaderInstructions, announcements }}
              >
                <SortableContext items={rules.map((rule) => rule.id)} strategy={verticalListSortingStrategy}>
                  <ul className="divide-y divide-border">
                    {rules.map((rule) => (
                      <SortableRuleRow
                        key={rule.id}
                        rule={rule}
                        lookups={lookups}
                        pending={pendingRuleId === rule.id}
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
