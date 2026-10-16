import type { Direction, RuleConditionNodeInput, RuleFieldName, RuleOperatorName } from '@/api/types'
import { toConditionInput, type ConditionValues } from '@/features/rules/rule-form-values'

/**
 * Uma alternativa ("Se"/"Ou se") da regra criada pela tela Categorizar: todas as condições dela
 * precisam casar. A regra inteira casa quando qualquer alternativa casa (`match: any`), cada uma
 * enviada como um grupo `match: all`.
 */
export type RuleAlternative = { uid: string; conditions: ConditionValues[] }

/** O mínimo de um lançamento pendente que a contagem local enxerga. */
export type MatchableTransaction = {
  id: number
  description: string
  original_description: string
  direction: Direction
}

export type MatchCount = { kind: 'local'; matched: number; extra: number } | { kind: 'server' }

/** Mesmo limite de texto de condição do backend (`RuleDefinitionValidator`). */
const MAX_CONDITION_TEXT = 200
/** Mesmo limite de nome de regra do backend (`StoreRuleRequest`, `ApplyCategorizationRequest`). */
const MAX_RULE_NAME = 80

let uidCounter = 0
function nextUid(): string {
  uidCounter += 1
  return `categorize-rule-${uidCounter}`
}

export function condition(field: RuleFieldName, op: RuleOperatorName, value: string): ConditionValues {
  return { kind: 'condition', field, op, value, uid: nextUid() }
}

export function emptyAlternative(): RuleAlternative {
  return { uid: nextUid(), conditions: [condition('description', 'contains', '')] }
}

/**
 * Regra padrão: uma alternativa por grupo marcado, cada uma com `original_description contém
 * <nome do grupo>` (o nome sai do texto do banco, que não muda quando o usuário renomeia) e
 * `direction é igual a <direção da seleção>`.
 */
export function defaultAlternatives(groupNames: string[], direction: Direction): RuleAlternative[] {
  return groupNames.map((name) => ({
    uid: nextUid(),
    conditions: [condition('original_description', 'contains', name.slice(0, MAX_CONDITION_TEXT)), condition('direction', 'equals', direction)],
  }))
}

/** `"<grupo> → <Pai › Sub>"`, com vários grupos unidos por ", " e cortado em 80 caracteres. */
export function defaultRuleName(groupNames: string[], categoryLabel: string): string {
  const name = `${groupNames.join(', ')} → ${categoryLabel}`
  return name.length <= MAX_RULE_NAME ? name : `${name.slice(0, MAX_RULE_NAME - 1).trimEnd()}…`
}

/** Corpo de `rule.conditions`: cada alternativa vira um grupo "todas". */
export function toRuleConditions(alternatives: RuleAlternative[]): RuleConditionNodeInput[] {
  return alternatives.map((alternative) => ({ match: 'all', conditions: alternative.conditions.map(toConditionInput) }))
}

/**
 * Mesma normalização do `TextNormalizer::normalize()` do backend: maiúsculas, sem acento,
 * espaços colapsados.
 */
export function normalizeRuleText(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/\s+/g, ' ')
    .trim()
}

const LOCAL_TEXT_FIELDS = new Set<RuleFieldName>(['description', 'original_description'])
const LOCAL_TEXT_OPERATORS = new Set<RuleOperatorName>(['contains', 'not_contains', 'starts_with', 'ends_with', 'equals', 'not_equals'])
const LOCAL_DIRECTION_OPERATORS = new Set<RuleOperatorName>(['equals', 'not_equals'])

/** Verdadeiro se a condição pode ser avaliada aqui, com os campos que a lista de pendentes traz. */
function isLocallyEvaluable(value: ConditionValues): boolean {
  if (LOCAL_TEXT_FIELDS.has(value.field)) return LOCAL_TEXT_OPERATORS.has(value.op)
  if (value.field === 'direction') return LOCAL_DIRECTION_OPERATORS.has(value.op)
  return false
}

/**
 * Algum campo/operador que a tela não consegue avaliar (favorecido, notas, valor, conta, data,
 * regex): a contagem precisa vir de `POST /rules/preview`.
 */
export function needsServerPreview(alternatives: RuleAlternative[]): boolean {
  return alternatives.some((alternative) => alternative.conditions.some((value) => !isLocallyEvaluable(value)))
}

function textMatches(op: RuleOperatorName, haystack: string, value: string): boolean {
  const needle = normalizeRuleText(value)
  switch (op) {
    case 'contains':
      return needle !== '' && haystack.includes(needle)
    case 'not_contains':
      return needle === '' || !haystack.includes(needle)
    case 'starts_with':
      return needle !== '' && haystack.startsWith(needle)
    case 'ends_with':
      return needle !== '' && haystack.endsWith(needle)
    case 'equals':
      return haystack === needle
    case 'not_equals':
      return haystack !== needle
    default:
      return false
  }
}

function conditionMatches(value: ConditionValues, transaction: MatchableTransaction): boolean {
  if (value.field === 'direction') {
    const same = transaction.direction === value.value
    return value.op === 'equals' ? same : value.op === 'not_equals' ? !same : false
  }
  if (value.field === 'description') return textMatches(value.op, normalizeRuleText(transaction.description), value.value)
  if (value.field === 'original_description') return textMatches(value.op, normalizeRuleText(transaction.original_description), value.value)
  return false
}

/** Alternativa sem condição nunca casa, como um grupo vazio no `RuleMatcher`. */
function alternativeMatches(alternative: RuleAlternative, transaction: MatchableTransaction): boolean {
  return alternative.conditions.length > 0 && alternative.conditions.every((value) => conditionMatches(value, transaction))
}

/**
 * "Casa N pendentes (K além dos marcados)" sobre os pendentes já carregados. Só os da mesma
 * direção da seleção contam: o backend aplica a regra aos pendentes só nessa direção. Com algum
 * campo que não dá para avaliar aqui, devolve `server` (quem chama usa a prévia do servidor).
 */
export function countRuleMatches(
  alternatives: RuleAlternative[],
  transactions: MatchableTransaction[],
  selectedIds: ReadonlySet<number>,
  direction: Direction,
): MatchCount {
  if (needsServerPreview(alternatives)) return { kind: 'server' }

  let matched = 0
  let extra = 0
  for (const transaction of transactions) {
    if (transaction.direction !== direction) continue
    if (!alternatives.some((alternative) => alternativeMatches(alternative, transaction))) continue
    matched += 1
    if (!selectedIds.has(transaction.id)) extra += 1
  }
  return { kind: 'local', matched, extra }
}
