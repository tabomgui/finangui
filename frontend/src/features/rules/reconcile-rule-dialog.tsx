import { ChevronDown, LoaderCircle, TriangleAlert } from 'lucide-react'
import { useId, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { useCategories } from '@/api/queries/categories'
import { useApplyRuleReconcile } from '@/api/queries/rules'
import type { Category, ReconcileGroupKey, RuleReconcile, RuleReconcileGroup, RuleReconcileResult } from '@/api/types'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { categoryPathById } from '@/lib/category-path'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'
import { launchCount, nonEmptyReconcileGroups, reconcileGroupCheckedByDefault, reconcileGroupLabel } from './category-change-labels'
import { CategoryChangeRow } from './category-change-row'
import { pluralize } from './rule-labels'

const FOCUS_RING = 'outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

type ReconcileRuleDialogProps = {
  ruleId: number
  /** Categoria que a regra salva define (para os rótulos), ou `null` se ela não define. */
  categoryId: number | null
  /** Resposta de `GET /rules/{rule}/reconcile`; o diálogo só é montado quando há algo a mudar. */
  reconcile: RuleReconcile
  onClose: () => void
}

function resultMessage(result: RuleReconcileResult): string {
  const updated = result.recategorize + result.fill + result.clear
  const parts = [`${updated} ${pluralize(updated, 'lançamento atualizado', 'lançamentos atualizados')}`]
  if (result.skipped > 0) parts.push(`${result.skipped} ${pluralize(result.skipped, 'pulado porque mudou', 'pulados porque mudaram')}`)
  return parts.join(' · ')
}

type GroupBlockProps = {
  group: RuleReconcileGroup
  label: string
  checked: boolean
  disabled: boolean
  onToggle: (key: ReconcileGroupKey) => void
  categoriesById: ReadonlyMap<number, Category>
  categoriesLoading: boolean
}

function GroupBlock({ group, label, checked, disabled, onToggle, categoriesById, categoriesLoading }: GroupBlockProps) {
  const [expanded, setExpanded] = useState(false)
  const sampleId = useId()
  const checkboxId = useId()
  const labelId = useId()
  const hidden = group.count - group.sample.length

  return (
    <div className="min-w-0 overflow-hidden rounded-xl border border-border">
      <div className="flex items-start gap-3 p-3">
        <Checkbox id={checkboxId} checked={checked} disabled={disabled} onCheckedChange={() => onToggle(group.key)} className="mt-0.5" />
        {/* A contagem fica dentro do rótulo: o leitor de tela lê "… (12)" junto com a caixa. */}
        <label id={labelId} htmlFor={checkboxId} className="flex min-w-0 flex-1 cursor-pointer items-start gap-3 text-sm">
          <span className="min-w-0 flex-1 break-words">{label}</span>{' '}
          <span className="shrink-0 font-semibold tabular-nums">
            <span className="sr-only">(</span>
            {group.count}
            <span className="sr-only">)</span>
          </span>
        </label>
      </div>
      {group.sample.length > 0 && (
        <>
          <button
            type="button"
            aria-expanded={expanded}
            aria-controls={expanded ? sampleId : undefined}
            aria-describedby={labelId}
            onClick={() => setExpanded((value) => !value)}
            className={cn('flex w-full items-center gap-1 border-t border-border px-3 py-2 text-xs font-medium text-primary hover:bg-muted/50', FOCUS_RING)}
          >
            {expanded ? 'Ocultar exemplos' : 'Ver exemplos'}
            <ChevronDown aria-hidden className={cn('h-3.5 w-3.5 transition-transform', expanded && 'rotate-180')} />
          </button>
          {expanded && (
            <div id={sampleId} className="min-w-0 border-t border-border">
              <ul className="divide-y divide-border">
                {group.sample.map((item) => (
                  <li key={item.transaction_id} className="min-w-0">
                    <CategoryChangeRow item={item} categoriesById={categoriesById} categoriesLoading={categoriesLoading} />
                  </li>
                ))}
              </ul>
              {hidden > 0 && <p className="px-3 py-2 text-xs text-muted-foreground">e mais {launchCount(hidden)}</p>}
            </div>
          )}
        </>
      )}
    </div>
  )
}

/**
 * Oferecido logo depois de salvar uma regra editada: o que a versão nova mudaria na categoria dos
 * lançamentos que já existem, por grupo, cada um com checkbox (tirar categoria começa desmarcado).
 * O backend recalcula tudo ao aplicar e recusa acima de `max_changes`.
 */
export function ReconcileRuleDialog({ ruleId, categoryId, reconcile, onClose }: ReconcileRuleDialogProps) {
  const groups = nonEmptyReconcileGroups(reconcile)
  const [selected, setSelected] = useState<Set<ReconcileGroupKey>>(
    () => new Set(groups.filter(reconcileGroupCheckedByDefault).map((group) => group.key)),
  )
  const apply = useApplyRuleReconcile()
  const { data: categories, isPending: categoriesLoading } = useCategories(true)
  const categoriesById = useMemo(() => new Map((categories ?? []).map((category) => [category.id, category] as const)), [categories])
  const categoryLabel = categoryId === null ? 'a categoria da regra' : categoryPathById(categoryId, categoriesById, categoriesLoading)

  const total = groups.filter((group) => selected.has(group.key)).reduce((sum, group) => sum + group.count, 0)
  const overLimit = total > reconcile.max_changes

  const toggle = (key: ReconcileGroupKey) => {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const confirm = async () => {
    if (apply.isPending || total === 0 || overLimit) return
    try {
      const result = await apply.mutateAsync({ id: ruleId, body: { groups: groups.filter((group) => selected.has(group.key)).map((group) => group.key) } })
      const updated = result.recategorize + result.fill + result.clear
      if (updated > 0) toast.success(resultMessage(result))
      else toast(result.skipped > 0 ? resultMessage(result) : 'Nenhum lançamento precisou mudar.')
      onClose()
    } catch (error) {
      // 409 (acima do limite de alterações) traz a mensagem pronta do backend; o diálogo continua
      // aberto para desmarcar algum grupo.
      notifyError(error)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !apply.isPending && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-x-hidden overflow-y-auto">
        <DialogHeader className="min-w-0 pr-8">
          <DialogTitle>Atualizar lançamentos desta regra?</DialogTitle>
          <DialogDescription>
            Alguns lançamentos não batem com a regra como ela está agora. Os que você categorizou à mão nunca mudam.
          </DialogDescription>
        </DialogHeader>

        <div className="min-w-0 space-y-2 overflow-hidden">
          {groups.map((group) => (
            <GroupBlock
              key={group.key}
              group={group}
              label={reconcileGroupLabel(group, categoryLabel)}
              checked={selected.has(group.key)}
              disabled={apply.isPending}
              onToggle={toggle}
              categoriesById={categoriesById}
              categoriesLoading={categoriesLoading}
            />
          ))}
        </div>

        {overLimit && (
          <p role="alert" className="flex items-start gap-2 text-xs text-destructive">
            <TriangleAlert aria-hidden className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            São {total} lançamentos marcados; o máximo é {reconcile.max_changes} por vez. Desmarque algum grupo.
          </p>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" disabled={apply.isPending} onClick={onClose}>
            Agora não
          </Button>
          <Button type="button" disabled={total === 0 || overLimit || apply.isPending} onClick={confirm}>
            {apply.isPending && <LoaderCircle aria-hidden className="h-4 w-4 animate-spin" />}
            {total === 0 ? 'Marque o que atualizar' : `Atualizar ${launchCount(total)}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
