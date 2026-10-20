import type { CategoryChangeItem, Category } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Checkbox } from '@/components/ui/checkbox'
import { categoryPathById } from '@/lib/category-path'
import { formatDate } from '@/lib/date'

type CategoryChangeRowProps = {
  item: CategoryChangeItem
  categoriesById: ReadonlyMap<number, Category>
  categoriesLoading: boolean
  /**
   * Revisão: mostra de onde vem a categoria atual ("Hoje: X (histórico)") e a regra que decide,
   * marcando a desativada. A reconciliação é sempre a mesma regra, então não precisa.
   */
  showOrigin?: boolean
  /** Com seleção, a linha inteira vira o rótulo do checkbox. */
  selection?: { checked: boolean; disabled?: boolean; onToggle: (id: number) => void }
}

function sourceLabel(item: CategoryChangeItem): string | null {
  switch (item.current_source) {
    case 'manual':
      return 'à mão'
    case 'history':
      return 'histórico'
    case 'pluggy':
      return 'banco'
    case 'rule':
      return item.current_rule_name ? `regra ${item.current_rule_name}` : 'regra excluída'
    default:
      return null
  }
}

/**
 * Um lançamento cuja categoria muda por regra: descrição e valor na primeira linha, data e conta,
 * e "atual → nova" ("sem categoria" dos dois lados quando não há) com a largura toda, para o
 * caminho da categoria não ficar espremido ao lado do valor. Usada na reconciliação e na revisão.
 */
export function CategoryChangeRow({ item, categoriesById, categoriesLoading, showOrigin = false, selection }: CategoryChangeRowProps) {
  const current = categoryPathById(item.current_category_id, categoriesById, categoriesLoading)
  const next = categoryPathById(item.new_category_id, categoriesById, categoriesLoading)
  const source = showOrigin && item.current_category_id !== undefined ? sourceLabel(item) : null

  const content = (
    <>
      {selection && (
        <Checkbox
          checked={selection.checked}
          disabled={selection.disabled}
          onCheckedChange={() => selection.onToggle(item.transaction_id)}
          aria-label={`Selecionar ${item.description}`}
          className="mt-0.5"
        />
      )}
      <span className="min-w-0 flex-1 space-y-0.5">
        <span className="flex items-baseline gap-3">
          <span className="min-w-0 flex-1 truncate text-sm">{item.description}</span>
          <MoneyText cents={item.amount} direction={item.direction} className="shrink-0 text-sm font-semibold" />
        </span>
        <span className="block truncate text-xs text-muted-foreground">
          {formatDate(item.date)} · {item.account_name}
        </span>
        <span className="block text-xs break-words">
          <span className="text-muted-foreground">
            {showOrigin && item.current_category_id !== undefined && 'Hoje: '}
            {current}
            {source && ` (${source})`}
          </span>{' '}
          → <span className="font-medium">{next}</span>
        </span>
        {showOrigin && item.rule_name && (
          <span className="block truncate text-xs text-muted-foreground">
            Regra: {item.rule_name}
            {item.rule_active === false && ' (desativada)'}
          </span>
        )}
      </span>
    </>
  )

  if (selection) {
    return (
      <label className="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-muted/50 has-[:disabled]:cursor-default">{content}</label>
    )
  }
  return <div className="flex items-start gap-3 px-3 py-2.5">{content}</div>
}
