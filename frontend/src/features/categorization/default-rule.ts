import type { Direction, RuleConditionInput, RuleConditionNodeInput, RuleFieldName, RuleOperatorName } from '@/api/types'
import { emptyCondition, nextUid, toConditionInput, type ConditionValues } from '@/features/rules/rule-form-values'

/**
 * Uma alternativa ("Se"/"Ou se") da regra que a tela Categorizar monta: todas as condições dela
 * precisam casar, e a regra casa quando qualquer alternativa casa. É a forma que o editor mostra;
 * o corpo enviado sai de `toRuleBody`, que compacta essa forma quando dá.
 */
export type RuleAlternative = { uid: string; conditions: ConditionValues[] }

/** Corpo de `rule.match`/`rule.conditions` do apply (e de `/rules/preview`). */
export type RuleConditionsBody = { match: 'all' | 'any'; conditions: RuleConditionNodeInput[] }

/** O mínimo de um lançamento pendente que a contagem local enxerga. */
export type MatchableTransaction = {
  id: number
  description: string
  original_description: string
  direction: Direction
}

export type MatchCount = { kind: 'local'; matched: number; extra: number } | { kind: 'server' }

/**
 * Máximo de condições de uma regra, contando as de dentro dos grupos — o mesmo
 * `RuleDefinitionValidator::MAX_CONDITIONS` do backend, que valida de novo na gravação. A regra
 * padrão compacta usa uma condição de direção mais um termo por grupo.
 */
export const MAX_RULE_CONDITIONS = 20
/** Quantos grupos cabem na regra padrão: o limite menos a condição de direção. */
export const MAX_DEFAULT_RULE_GROUPS = MAX_RULE_CONDITIONS - 1

/** Mesmo limite de texto de condição do backend (`RuleDefinitionValidator`). */
const MAX_CONDITION_TEXT = 200
/** Mesmo limite de nome de regra do backend (`StoreRuleRequest`, `ApplyCategorizationRequest`). */
const MAX_RULE_NAME = 80

export function condition(field: RuleFieldName, op: RuleOperatorName, value: string): ConditionValues {
  return { kind: 'condition', field, op, value, uid: nextUid() }
}

export function emptyAlternative(): RuleAlternative {
  return { uid: nextUid(), conditions: [emptyCondition()] }
}

/**
 * Regra padrão: uma alternativa por grupo marcado (nomes repetidos entram uma vez só), cada uma com
 * `original_description contém <nome do grupo>` (o nome sai do texto do banco, que não muda quando
 * o usuário renomeia) e `direction é igual a <direção da seleção>`.
 */
export function defaultAlternatives(groupNames: string[], direction: Direction): RuleAlternative[] {
  return [...new Set(groupNames)].map((name) => ({
    uid: nextUid(),
    conditions: [condition('original_description', 'contains', name.slice(0, MAX_CONDITION_TEXT)), condition('direction', 'equals', direction)],
  }))
}

/** `"<grupo> → <Pai › Sub>"`, com vários grupos unidos por ", " e cortado em 80 caracteres. */
export function defaultRuleName(groupNames: string[], categoryLabel: string): string {
  const name = `${groupNames.join(', ')} → ${categoryLabel}`
  return name.length <= MAX_RULE_NAME ? name : `${name.slice(0, MAX_RULE_NAME - 1).trimEnd()}…`
}

const conditionKey = (value: RuleConditionInput) => JSON.stringify([value.field, value.op, value.value])

/**
 * O corpo compactado e, para cada caminho de condição dele (`conditions.1.conditions.3`), o
 * caminho da mesma condição no editor (`conditions.3.conditions.0`, alternativa e posição) — um
 * 422 do backend aponta para o corpo enviado, e o editor mostra o erro na linha certa.
 */
export type CompactRule = { body: RuleConditionsBody; origins: Record<string, string> }

const editorPath = (alternative: number, position: number) => `conditions.${alternative}.conditions.${position}`

/**
 * Corpo da regra a partir das alternativas, gastando o mínimo de condições (o backend conta as de
 * dentro dos grupos no limite):
 * - uma alternativa só: as condições dela, "todas";
 * - condições comuns a todas as alternativas e uma condição a mais em cada uma (a regra padrão:
 *   mesma direção, um termo por grupo): `todas [comuns..., qualquer [termos...]]` — 1 + N em vez
 *   de 2N;
 * - uma condição por alternativa e nada em comum: `qualquer [condições...]`;
 * - senão, uma alternativa por grupo "todas" dentro de `qualquer`.
 *
 * As formas são equivalentes: (C e A) ou (C e B) = C e (A ou B).
 */
export function compactRule(alternatives: RuleAlternative[]): CompactRule {
  const inputs = alternatives.map((alternative) => alternative.conditions.map(toConditionInput))
  const origins: Record<string, string> = {}

  if (inputs.length === 1) {
    inputs[0].forEach((_, j) => (origins[`conditions.${j}`] = editorPath(0, j)))
    return { body: { match: 'all', conditions: inputs[0] }, origins }
  }

  // Comuns: condições da primeira alternativa (sem repetir) presentes em todas as outras.
  const first = inputs[0] ?? []
  const commonPositions = first
    .map((c, j) => ({ key: conditionKey(c), j }))
    .filter(({ key, j }) => first.findIndex((other) => conditionKey(other) === key) === j)
    .filter(({ key }) => inputs.every((each) => each.some((c) => conditionKey(c) === key)))
  const commonKeys = new Set(commonPositions.map(({ key }) => key))
  const rest = inputs.map((each) => each.map((c, j) => ({ c, j })).filter(({ c }) => !commonKeys.has(conditionKey(c))))

  if (inputs.length > 0 && rest.every((each) => each.length === 1)) {
    const terms = rest.map((each) => each[0].c)
    if (commonPositions.length === 0) {
      rest.forEach((each, i) => (origins[`conditions.${i}`] = editorPath(i, each[0].j)))
      return { body: { match: 'any', conditions: terms }, origins }
    }
    commonPositions.forEach(({ j }, index) => (origins[`conditions.${index}`] = editorPath(0, j)))
    const group = commonPositions.length
    rest.forEach((each, i) => (origins[`conditions.${group}.conditions.${i}`] = editorPath(i, each[0].j)))
    return {
      body: { match: 'all', conditions: [...commonPositions.map(({ j }) => first[j]), { match: 'any', conditions: terms }] },
      origins,
    }
  }

  inputs.forEach((each, i) => each.forEach((_, j) => (origins[`conditions.${i}.conditions.${j}`] = editorPath(i, j))))
  return { body: { match: 'any', conditions: inputs.map((conditions) => ({ match: 'all', conditions })) }, origins }
}

export function toRuleBody(alternatives: RuleAlternative[]): RuleConditionsBody {
  return compactRule(alternatives).body
}

/**
 * Traduz erros por caminho do corpo enviado (sem o prefixo `rule.`) para os caminhos do editor.
 * Um erro que não é de uma condição específica (a lista inteira, um grupo, `match`) vira erro
 * geral da regra (`conditions`).
 */
export function editorErrors(errors: Record<string, string>, origins: Record<string, string>): Record<string, string> {
  const mapped: Record<string, string> = {}
  for (const [path, message] of Object.entries(errors)) {
    const match = /^(conditions\.\d+(?:\.conditions\.\d+)?)\.(field|op|value)$/.exec(path)
    const origin = match ? origins[match[1]] : undefined
    if (match && origin) mapped[`${origin}.${match[2]}`] = message
    else mapped.conditions ??= message
  }
  return mapped
}

/** Condições que contam no limite do backend: cada condição simples, inclusive as de dentro de grupos. */
export function ruleConditionCount(conditions: RuleConditionNodeInput[]): number {
  return conditions.reduce((total, node) => total + ('conditions' in node ? node.conditions.length : 1), 0)
}

/**
 * Transliterações que o `Str::ascii()` do backend faz e que a decomposição Unicode (NFKD) não
 * resolve sozinha. Cobre o que aparece em descrição de banco brasileiro; um texto em outro
 * alfabeto (cirílico, grego…) o backend translitera e aqui não — a contagem local pode divergir
 * nesse caso, a gravação e a aplicação da regra seguem a do backend.
 */
const TRANSLITERATIONS: Record<string, string> = {
  ø: 'o',
  Ø: 'O',
  æ: 'ae',
  Æ: 'AE',
  œ: 'oe',
  Œ: 'OE',
  ł: 'l',
  Ł: 'L',
  đ: 'd',
  Đ: 'D',
  ß: 'ss',
  '‐': '-',
  '‑': '-',
  '‒': '-',
  '–': '-',
  '—': '-',
  '―': '-',
  '−': '-',
  '‘': "'",
  '’': "'",
  '‚': "'",
  '‛': "'",
  '“': '"',
  '”': '"',
  '„': '"',
  '‟': '"',
}
const TRANSLITERATION_PATTERN = new RegExp(`[${Object.keys(TRANSLITERATIONS).join('')}]`, 'g')

/**
 * Mesma normalização do `TextNormalizer::normalize()` do backend: sem acento (NFKD, que também
 * resolve º/ª em o/a), maiúsculas, espaços colapsados.
 */
export function normalizeRuleText(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(TRANSLITERATION_PATTERN, (char) => TRANSLITERATIONS[char] ?? char)
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

/** Um lançamento com os textos já normalizados, uma vez só por contagem. */
type Subject = { description: string; original_description: string; direction: Direction }
type Predicate = (subject: Subject) => boolean

function textPredicate(field: 'description' | 'original_description', op: RuleOperatorName, value: string): Predicate {
  const needle = normalizeRuleText(value)
  switch (op) {
    case 'contains':
      return (subject) => needle !== '' && subject[field].includes(needle)
    case 'not_contains':
      return (subject) => needle === '' || !subject[field].includes(needle)
    case 'starts_with':
      return (subject) => needle !== '' && subject[field].startsWith(needle)
    case 'ends_with':
      return (subject) => needle !== '' && subject[field].endsWith(needle)
    case 'equals':
      return (subject) => subject[field] === needle
    case 'not_equals':
      return (subject) => subject[field] !== needle
    default:
      return () => false
  }
}

/** Compila a condição uma vez (o valor normalizado não é recalculado a cada lançamento). */
function conditionPredicate(value: ConditionValues): Predicate {
  if (value.field === 'direction') {
    if (value.op === 'equals') return (subject) => subject.direction === value.value
    if (value.op === 'not_equals') return (subject) => subject.direction !== value.value
    return () => false
  }
  if (value.field === 'description' || value.field === 'original_description') return textPredicate(value.field, value.op, value.value)
  return () => false
}

/**
 * "Casa N pendentes (K além dos marcados)" sobre os pendentes já carregados, comparando como o
 * `RuleMatcher` (alternativa sem condição nunca casa, como um grupo vazio lá). Só os da mesma
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

  const compiled = alternatives.map((alternative) => alternative.conditions.map(conditionPredicate))
  const matches = (subject: Subject) => compiled.some((predicates) => predicates.length > 0 && predicates.every((predicate) => predicate(subject)))

  let matched = 0
  let extra = 0
  for (const transaction of transactions) {
    if (transaction.direction !== direction) continue
    const subject: Subject = {
      description: normalizeRuleText(transaction.description),
      original_description: normalizeRuleText(transaction.original_description),
      direction: transaction.direction,
    }
    if (!matches(subject)) continue
    matched += 1
    if (!selectedIds.has(transaction.id)) extra += 1
  }
  return { kind: 'local', matched, extra }
}
