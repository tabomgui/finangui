import { arrayMove } from '@dnd-kit/sortable'
import type { Rule, RuleAction, RuleCondition, RuleConditionGroup, RuleSimpleCondition } from '@/api/types'
import { formatDate } from '@/lib/date'
import { formatMoney } from '@/lib/money'

export const FIELD_LABELS: Record<string, string> = {
  description: 'Descrição',
  original_description: 'Descrição original',
  payee: 'Favorecido',
  notes: 'Notas',
  amount: 'Valor',
  direction: 'Tipo',
  account_id: 'Conta',
  date: 'Data',
}

export const OPERATOR_LABELS: Record<string, string> = {
  contains: 'contém',
  not_contains: 'não contém',
  starts_with: 'começa com',
  ends_with: 'termina com',
  equals: 'é igual a',
  not_equals: 'é diferente de',
  regex: 'casa a expressão',
  gt: 'maior que',
  gte: 'maior ou igual a',
  lt: 'menor que',
  lte: 'menor ou igual a',
}

const TEXT_OPERATORS = ['contains', 'not_contains', 'starts_with', 'ends_with', 'equals', 'not_equals', 'regex']
const COMPARISON_OPERATORS = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte']
const EQUALITY_OPERATORS = ['equals', 'not_equals']

// Espelha `RuleField::operators()` no backend: é só configuração de formulário (quais operadores
// oferecer por campo), não regra de negócio — o backend valida de novo na gravação.
export const OPERATORS_BY_FIELD: Record<string, string[]> = {
  description: TEXT_OPERATORS,
  original_description: TEXT_OPERATORS,
  payee: TEXT_OPERATORS,
  notes: TEXT_OPERATORS,
  amount: COMPARISON_OPERATORS,
  date: COMPARISON_OPERATORS,
  direction: EQUALITY_OPERATORS,
  account_id: EQUALITY_OPERATORS,
}

export const ACTION_LABELS: Record<string, string> = {
  set_category: 'Definir categoria',
  set_description: 'Definir descrição',
  set_payee: 'Definir favorecido',
  add_tag: 'Adicionar tag',
  ignore: 'Ignorar lançamento',
}

const DIRECTION_VALUE_LABELS: Record<string, string> = { in: 'entrada', out: 'saída' }

export type RuleLookups = {
  categoryName: (id: number) => string | undefined
  tagName: (id: number) => string | undefined
}

function isGroup(condition: RuleCondition): condition is RuleConditionGroup {
  return 'conditions' in condition
}

function formatConditionValue(condition: RuleSimpleCondition): string {
  const { field, value } = condition
  if (field === 'amount' && typeof value === 'number') return formatMoney(value)
  if (field === 'date' && typeof value === 'string') return formatDate(value)
  if (field === 'direction') return DIRECTION_VALUE_LABELS[String(value)] ?? String(value)
  return `"${value}"`
}

function describeSimpleCondition(condition: RuleSimpleCondition): string {
  const field = FIELD_LABELS[condition.field] ?? condition.field
  const operator = OPERATOR_LABELS[condition.op] ?? condition.op
  return `${field} ${operator} ${formatConditionValue(condition)}`
}

function describeCondition(condition: RuleCondition): string {
  if (isGroup(condition)) {
    const connector = condition.match === 'any' ? ' ou ' : ' e '
    return `(${condition.conditions.map(describeSimpleCondition).join(connector)})`
  }
  return describeSimpleCondition(condition)
}

function describeAction(action: RuleAction, lookups: RuleLookups): string | null {
  switch (action.type) {
    case 'set_category': {
      if (action.category_id === undefined) return null
      return lookups.categoryName(action.category_id) ?? 'categoria excluída'
    }
    case 'set_description':
      return action.value ? `descrição "${action.value}"` : null
    case 'set_payee':
      return action.value ? `favorecido "${action.value}"` : null
    case 'add_tag': {
      if (action.tag_id === undefined) return null
      const name = lookups.tagName(action.tag_id)
      return name ? `+#${name}` : '+tag excluída'
    }
    case 'ignore':
      return 'ignorar'
    default:
      return null
  }
}

/** Resumo em uma linha usado na lista de regras: condições + a primeira ação de cada tipo. */
export function describeRule(rule: Rule, lookups: RuleLookups): string {
  const connector = rule.match === 'any' ? ' ou ' : ' e '
  const conditions = rule.conditions.map(describeCondition).join(connector)
  const actions = rule.actions
    .map((action) => describeAction(action, lookups))
    .filter((text): text is string => text !== null)
    .join(' · ')
  return actions ? `${conditions} → ${actions}` : conditions
}

/** Aplica o `DragEndEvent` do dnd-kit (ids de `active`/`over`) a uma lista de ids ordenada. */
export function reorderIds(ids: number[], activeId: number, overId: number): number[] {
  const from = ids.indexOf(activeId)
  const to = ids.indexOf(overId)
  if (from === -1 || to === -1) return ids
  return arrayMove(ids, from, to)
}
