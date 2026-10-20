import type { CategoryChangeItem, Category } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Checkbox } from '@/components/ui/checkbox'
import { categoryPathById } from '@/lib/category-path'
import { formatDate } from '@/lib/date'

type CategoryChangeRowProps = {
  item: CategoryChangeItem
  categoriesById: ReadonlyMap<number, Category>
  categoriesLoading: boolean
  /** Mostra "Regra: nome" (a revisão cruza várias regras; a reconciliação é sempre a mesma). */
  showRule?: boolean
  /** Com seleção, a linha inteira vira o rótulo do checkbox. */
  selection?: { checked: boolean; onToggle: (id: number) => void }
}

/**
 * Um lançamento cuja categoria muda por regra: descrição, data, conta, valor e "atual → nova"
 * ("sem categoria" dos dois lados quando não há). Usada na reconciliação e na revisão de regras.
 */
export function CategoryChangeRow({ item, categoriesById, categoriesLoading, showRule = false, selection }: CategoryChangeRowProps) {
  const current = categoryPathById(item.current_category_id, categoriesById, categoriesLoading)
  const next = categoryPathById(item.new_category_id, categoriesById, categoriesLoading)

  const content = (
    <>
      {selection && (
        <Checkbox
          checked={selection.checked}
          onCheckedChange={() => selection.onToggle(item.transaction_id)}
          aria-label={`Selecionar ${item.description}`}
          className="mt-0.5"
        />
      )}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm">{item.description}</span>
        <span className="block truncate text-xs text-muted-foreground">
          {formatDate(item.date)} · {item.account_name}
        </span>
        <span className="block truncate text-xs">
          <span className="text-muted-foreground">{current}</span> → <span className="font-medium">{next}</span>
        </span>
        {showRule && item.rule_name && <span className="block truncate text-xs text-muted-foreground">Regra: {item.rule_name}</span>}
      </span>
      <MoneyText cents={item.amount} direction={item.direction} className="shrink-0 text-sm font-semibold" />
    </>
  )

  if (selection) {
    return <label className="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-muted/50">{content}</label>
  }
  return <div className="flex items-start gap-3 px-3 py-2.5">{content}</div>
}
