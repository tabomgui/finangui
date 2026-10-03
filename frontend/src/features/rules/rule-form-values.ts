import { z } from 'zod'
import type {
  Rule,
  RuleAction,
  RuleActionInput,
  RuleActionTypeName,
  RuleBody,
  RuleCondition,
  RuleConditionInput,
  RuleConditionNodeInput,
  RuleFieldName,
  RuleOperatorName,
  Transaction,
} from '@/api/types'

// Espelham os enums do backend (`RuleField`, `RuleOperator`, `RuleActionType`): servem só para
// tipar o formulário com união literal (via `z.enum`), não são regra de negócio — o backend
// valida de novo na gravação.
const FIELD_NAMES = [
  'description',
  'original_description',
  'payee',
  'notes',
  'amount',
  'direction',
  'account_id',
  'date',
] as const satisfies readonly RuleFieldName[]

const OPERATOR_NAMES = [
  'contains',
  'not_contains',
  'starts_with',
  'ends_with',
  'equals',
  'not_equals',
  'regex',
  'gt',
  'gte',
  'lt',
  'lte',
] as const satisfies readonly RuleOperatorName[]

const ACTION_TYPES = ['set_category', 'set_description', 'set_payee', 'add_tag', 'ignore'] as const satisfies readonly RuleActionTypeName[]

// Valor sempre string no formulário: texto, centavos (via MoneyInput) convertidos para string,
// "in"/"out", id de conta como string e data ISO. `toRuleBody` converte para número onde a API espera.
const conditionValuesSchema = z.object({
  kind: z.literal('condition'),
  field: z.enum(FIELD_NAMES),
  op: z.enum(OPERATOR_NAMES),
  value: z.string(),
})

// Só um nível: grupo dentro de grupo é inválido (o editor nunca oferece essa opção).
const groupValuesSchema = z.object({
  kind: z.literal('group'),
  match: z.enum(['all', 'any']),
  conditions: z.array(conditionValuesSchema).min(1, 'Adicione pelo menos uma condição no grupo.'),
})

const conditionOrGroupSchema = z.union([conditionValuesSchema, groupValuesSchema])

const actionValuesSchema = z.object({
  type: z.enum(ACTION_TYPES),
  category_id: z.number().nullable(),
  tag_id: z.number().nullable(),
  value: z.string(),
})

// `uid` só existe no formulário (nunca no corpo enviado): chave de React estável por item de
// lista, já que grupos não usam `useFieldArray` (o item é um objeto só, não um array próprio).
// Opcional no tipo porque o zod não sabe dele — `toRuleBody`/validação ignoram a propriedade.
export type ConditionValues = z.infer<typeof conditionValuesSchema> & { uid?: string }
export type GroupValues = Omit<z.infer<typeof groupValuesSchema>, 'conditions'> & { conditions: ConditionValues[]; uid?: string }
export type ConditionOrGroupValues = ConditionValues | GroupValues
export type ActionValues = z.infer<typeof actionValuesSchema> & { uid?: string }

let uidCounter = 0
function nextUid(): string {
  uidCounter += 1
  return `rule-item-${uidCounter}`
}

/**
 * Validação local, deliberadamente simples (valor obrigatório; categoria/tag escolhida para as
 * ações que pedem). Regex e os limites finos (máximo de condições/ações/tags, regex que casa
 * string vazia, ids que não existem) ficam com o backend, que devolve 422 por caminho.
 */
function checkConditionsAndActions(values: { conditions: z.infer<typeof conditionOrGroupSchema>[]; actions: z.infer<typeof actionValuesSchema>[] }, ctx: z.RefinementCtx) {
  values.conditions.forEach((condition, index) => {
    if (condition.kind === 'group') {
      condition.conditions.forEach((child, childIndex) => {
        if (child.value.trim() === '') {
          ctx.addIssue({
            code: 'custom',
            message: 'Informe um valor.',
            path: ['conditions', index, 'conditions', childIndex, 'value'],
          })
        }
      })
      return
    }
    if (condition.value.trim() === '') {
      ctx.addIssue({ code: 'custom', message: 'Informe um valor.', path: ['conditions', index, 'value'] })
    }
  })

  values.actions.forEach((action, index) => {
    if (action.type === 'set_category' && action.category_id === null) {
      ctx.addIssue({ code: 'custom', message: 'Escolha a categoria.', path: ['actions', index, 'category_id'] })
    }
    if (action.type === 'add_tag' && action.tag_id === null) {
      ctx.addIssue({ code: 'custom', message: 'Escolha a tag.', path: ['actions', index, 'tag_id'] })
    }
    if ((action.type === 'set_description' || action.type === 'set_payee') && action.value.trim() === '') {
      ctx.addIssue({ code: 'custom', message: 'Informe um texto.', path: ['actions', index, 'value'] })
    }
  })
}

// Conteúdo sem `name`: usado tanto pela validação completa (editor) quanto pela prévia, que não
// precisa de nome nenhum para decidir se consulta a API — regra sem nome ainda pode ser
// consultada ao vivo enquanto o usuário monta condições e ações.
const ruleContentSchema = z.object({
  is_active: z.boolean(),
  match: z.enum(['all', 'any']),
  conditions: z.array(conditionOrGroupSchema).min(1, 'Adicione pelo menos uma condição.'),
  actions: z.array(actionValuesSchema).min(1, 'Adicione pelo menos uma ação.'),
})

export const ruleSchema = ruleContentSchema
  .extend({ name: z.string().trim().min(1, 'Informe o nome.').max(80, 'Use no máximo 80 caracteres.') })
  .superRefine(checkConditionsAndActions)

/** Mesma validação de condições/ações, sem exigir nome — usada só para decidir se a prévia consulta a API. */
export const rulePreviewSchema = ruleContentSchema.superRefine(checkConditionsAndActions)

export type RuleFormValues = {
  name: string
  is_active: boolean
  match: 'all' | 'any'
  conditions: ConditionOrGroupValues[]
  actions: ActionValues[]
}

export function emptyCondition(): ConditionValues {
  return { kind: 'condition', field: 'description', op: 'contains', value: '', uid: nextUid() }
}

export function emptyGroup(): GroupValues {
  return { kind: 'group', match: 'all', conditions: [emptyCondition()], uid: nextUid() }
}

export function emptyAction(type: RuleActionTypeName): ActionValues {
  return { type, category_id: null, tag_id: null, value: '', uid: nextUid() }
}

function conditionFromInput(condition: { field: RuleFieldName; op: RuleOperatorName; value: string | number }): ConditionValues {
  return { kind: 'condition', field: condition.field, op: condition.op, value: String(condition.value), uid: nextUid() }
}

function nodeFromInput(node: RuleCondition): ConditionOrGroupValues {
  if ('conditions' in node) {
    return { kind: 'group', match: node.match, conditions: node.conditions.map(conditionFromInput), uid: nextUid() }
  }
  return conditionFromInput(node)
}

function actionFromInput(action: RuleAction): ActionValues {
  return {
    type: action.type,
    category_id: action.category_id ?? null,
    tag_id: action.tag_id ?? null,
    value: action.value ?? '',
    uid: nextUid(),
  }
}

export function ruleDefaults(rule?: Rule): RuleFormValues {
  if (!rule) {
    return {
      name: '',
      is_active: true,
      match: 'all',
      conditions: [emptyCondition()],
      actions: [emptyAction('set_category')],
    }
  }

  return {
    name: rule.name,
    is_active: rule.is_active,
    match: rule.match,
    conditions: rule.conditions.map(nodeFromInput),
    actions: rule.actions.map(actionFromInput),
  }
}

/** Prefill de "Criar regra a partir desta": condição pela descrição, ação pela categoria (vazia, se não houver). */
export function ruleDefaultsFromTransaction(transaction: Transaction): RuleFormValues {
  return {
    name: transaction.description.slice(0, 80),
    is_active: true,
    match: 'all',
    conditions: [{ kind: 'condition', field: 'description', op: 'contains', value: transaction.description, uid: nextUid() }],
    actions: [{ type: 'set_category', category_id: transaction.category_id, tag_id: null, value: '', uid: nextUid() }],
  }
}

function toConditionInput(condition: ConditionValues): RuleConditionInput {
  const value = condition.field === 'amount' || condition.field === 'account_id' ? Number(condition.value) : condition.value
  return { field: condition.field, op: condition.op, value }
}

function toConditionNodeInput(node: ConditionOrGroupValues): RuleConditionNodeInput {
  if (node.kind === 'group') {
    return { match: node.match, conditions: node.conditions.map(toConditionInput) }
  }
  return toConditionInput(node)
}

// Mantém só as chaves de cada tipo de ação: `category_id`/`tag_id`/`value` só existem no
// formulário para não perder o que o usuário já preencheu ao trocar o tipo de ação no menu.
function toActionInput(action: ActionValues): RuleActionInput {
  switch (action.type) {
    case 'set_category':
      return { type: 'set_category', category_id: action.category_id as number }
    case 'set_description':
      return { type: 'set_description', value: action.value.trim() }
    case 'set_payee':
      return { type: 'set_payee', value: action.value.trim() }
    case 'add_tag':
      return { type: 'add_tag', tag_id: action.tag_id as number }
    case 'ignore':
      return { type: 'ignore' }
  }
}

export function toRuleBody(values: Pick<RuleFormValues, 'match' | 'conditions' | 'actions'>): RuleBody {
  return {
    match: values.match,
    conditions: values.conditions.map(toConditionNodeInput),
    actions: values.actions.map(toActionInput),
  }
}
