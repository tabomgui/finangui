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

/** Campos de texto livre: o valor digitado continua válido ao trocar de um para outro. */
export const TEXT_FIELDS = new Set<string>(['description', 'original_description', 'payee', 'notes'])

/** Singular/plural simples (português não tem irregularidade nos termos usados aqui). */
export function pluralize(count: number, singular: string, plural: string): string {
  return count === 1 ? singular : plural
}

// `applyFieldErrors('*', ...)` grava todo caminho que um 422 trouxer, mas nem todo caminho tem um
// campo visível que mostre a mensagem: o índice de um item sozinho ("conditions.2") e o tipo de
// uma ação ("actions.1.type") não renderizam nada. Usado por `rule-editor-page.tsx` para decidir
// se, mesmo com o erro "aplicado", ainda cabe um toast genérico — senão o usuário via um 422 que
// não aparecia em lugar nenhum da tela.
const UNCOVERED_ERROR_PATH_PATTERNS = [/^conditions\.\d+$/, /^actions\.\d+$/, /^actions\.\d+\.type$/]

export function isCoverablePath(path: string): boolean {
  return !UNCOVERED_ERROR_PATH_PATTERNS.some((pattern) => pattern.test(path))
}

export const ACTION_LABELS: Record<string, string> = {
  set_category: 'Definir categoria',
  set_description: 'Definir descrição',
  set_payee: 'Definir favorecido',
  add_tag: 'Adicionar tag',
  ignore: 'Ignorar lançamento',
}

const DIRECTION_VALUE_LABELS: Record<string, string> = { in: 'Entrada', out: 'Saída' }

// Enquanto as listas de categorias/tags/contas ainda não carregaram, um id sem nome não significa
// "excluído" — significa "ainda não sabemos". `loading` distingue os dois casos; sem ele, toda regra
// mostraria "categoria excluída" por um instante a cada carregamento da tela.
export const LOADING_PLACEHOLDER = '…'

export type RuleLookups = {
  categoryName: (id: number) => string | undefined
  tagName: (id: number) => string | undefined
  accountName: (id: number) => string | undefined
  loading: boolean
}

function isGroup(condition: RuleCondition): condition is RuleConditionGroup {
  return 'conditions' in condition
}

function missingLabel(lookups: RuleLookups, whenLoaded: string): string {
  return lookups.loading ? LOADING_PLACEHOLDER : whenLoaded
}

function formatConditionValue(condition: RuleSimpleCondition, lookups: RuleLookups): string {
  const { field, value } = condition
  if (field === 'amount' && typeof value === 'number') return formatMoney(value)
  if (field === 'date' && typeof value === 'string') return formatDate(value)
  if (field === 'direction') return DIRECTION_VALUE_LABELS[String(value)] ?? String(value)
  if (field === 'account_id' && typeof value === 'number') {
    return lookups.accountName(value) ?? missingLabel(lookups, 'conta excluída')
  }
  return `“${value}”`
}

function describeSimpleCondition(condition: RuleSimpleCondition, lookups: RuleLookups): string {
  const field = FIELD_LABELS[condition.field] ?? condition.field
  const operator = OPERATOR_LABELS[condition.op] ?? condition.op
  return `${field} ${operator} ${formatConditionValue(condition, lookups)}`
}

function describeCondition(condition: RuleCondition, lookups: RuleLookups): string {
  if (isGroup(condition)) {
    const connector = condition.match === 'any' ? ' ou ' : ' e '
    return `(${condition.conditions.map((child) => describeSimpleCondition(child, lookups)).join(connector)})`
  }
  return describeSimpleCondition(condition, lookups)
}

function describeAction(action: RuleAction, lookups: RuleLookups): string | null {
  switch (action.type) {
    case 'set_category': {
      if (action.category_id === undefined) return null
      return lookups.categoryName(action.category_id) ?? missingLabel(lookups, 'categoria excluída')
    }
    case 'set_description':
      return action.value ? `descrição “${action.value}”` : null
    case 'set_payee':
      return action.value ? `favorecido “${action.value}”` : null
    case 'add_tag': {
      if (action.tag_id === undefined) return null
      const name = lookups.tagName(action.tag_id)
      return name ? `+#${name}` : missingLabel(lookups, '+tag excluída')
    }
    case 'ignore':
      return 'ignorar'
    default:
      return null
  }
}

/** Resumo em uma linha usado na lista de regras: as condições seguidas das ações, na ordem em que aparecem na regra. */
export function describeRule(rule: Rule, lookups: RuleLookups): string {
  const connector = rule.match === 'any' ? ' ou ' : ' e '
  const conditions = rule.conditions.map((condition) => describeCondition(condition, lookups)).join(connector)
  const actions = rule.actions
    .map((action) => describeAction(action, lookups))
    .filter((text): text is string => text !== null)
    .join(' · ')
  return actions ? `${conditions} → ${actions}` : conditions
}
