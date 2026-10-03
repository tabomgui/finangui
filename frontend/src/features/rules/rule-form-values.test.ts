import { describe, expect, it } from 'vitest'
import type { Rule, Transaction } from '@/api/types'
import { ruleDefaults, ruleDefaultsFromTransaction, rulePreviewSchema, ruleSchema, toRuleBody } from './rule-form-values'

// `uid` é só chave de React (item de grupo/ação), não faz parte do valor que importa comparar aqui.
function stripUid<T>(value: T): T {
  if (Array.isArray(value)) return value.map((item) => stripUid(item)) as T
  if (value && typeof value === 'object') {
    const { uid: _uid, ...rest } = value as Record<string, unknown>
    return Object.fromEntries(Object.entries(rest).map(([key, item]) => [key, stripUid(item)])) as T
  }
  return value
}

function baseRule(overrides: Partial<Rule>): Rule {
  return {
    id: 1,
    name: 'Uber',
    priority: 1,
    is_active: true,
    match: 'all',
    conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
    actions: [{ type: 'set_category', category_id: 3 }],
    last_applied_at: null,
    last_applied_changes: null,
    ...overrides,
  }
}

function baseTransaction(overrides: Partial<Transaction>): Transaction {
  return {
    id: 10,
    account_id: 1,
    date: '2026-01-05',
    amount: 1500,
    direction: 'out',
    currency: 'BRL',
    description: 'Uber viagem',
    original_description: 'UBER* VIAGEM',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    tags: [],
    status: 'posted',
    source: 'manual',
    categorized_by: null,
    categorization: { source: 'manual' },
    transfer_id: null,
    statement_id: null,
    is_ignored: false,
    installment: null,
    ...overrides,
  } as Transaction
}

describe('ruleDefaults', () => {
  it('sem regra: uma condição e uma ação vazias, ativa por padrão', () => {
    const defaults = ruleDefaults()

    expect(stripUid(defaults)).toEqual({
      name: '',
      is_active: true,
      match: 'all',
      conditions: [{ kind: 'condition', field: 'description', op: 'contains', value: '' }],
      actions: [{ type: 'set_category', category_id: null, tag_id: null, value: '' }],
    })
  })

  it('com regra: carrega condições (inclusive grupo) e ações', () => {
    const rule = baseRule({
      conditions: [
        { field: 'description', op: 'contains', value: 'uber' },
        { match: 'any', conditions: [{ field: 'amount', op: 'gt', value: 1000 }] },
      ],
      actions: [
        { type: 'set_category', category_id: 3 },
        { type: 'add_tag', tag_id: 7 },
      ],
    })

    const defaults = ruleDefaults(rule)

    expect(stripUid(defaults.conditions)).toEqual([
      { kind: 'condition', field: 'description', op: 'contains', value: 'uber' },
      { kind: 'group', match: 'any', conditions: [{ kind: 'condition', field: 'amount', op: 'gt', value: '1000' }] },
    ])
    expect(stripUid(defaults.actions)).toEqual([
      { type: 'set_category', category_id: 3, tag_id: null, value: '' },
      { type: 'add_tag', category_id: null, tag_id: 7, value: '' },
    ])
  })
})

describe('ruleDefaultsFromTransaction', () => {
  it('com categoria: condição pela descrição e ação pela categoria', () => {
    const transaction = baseTransaction({ description: 'Uber viagem', category_id: 5 })

    expect(stripUid(ruleDefaultsFromTransaction(transaction))).toEqual({
      name: 'Uber viagem',
      is_active: true,
      match: 'all',
      conditions: [{ kind: 'condition', field: 'description', op: 'contains', value: 'Uber viagem' }],
      actions: [{ type: 'set_category', category_id: 5, tag_id: null, value: '' }],
    })
  })

  it('sem categoria: ação de categoria fica vazia', () => {
    const transaction = baseTransaction({ description: 'Mercado', category_id: null })

    expect(stripUid(ruleDefaultsFromTransaction(transaction).actions)).toEqual([
      { type: 'set_category', category_id: null, tag_id: null, value: '' },
    ])
  })

  it('trunca o nome em 80 caracteres', () => {
    const longDescription = 'x'.repeat(120)
    const transaction = baseTransaction({ description: longDescription })

    expect(ruleDefaultsFromTransaction(transaction).name).toHaveLength(80)
  })

  it('trunca o valor da condição em 200 caracteres (limite do backend)', () => {
    const longDescription = 'x'.repeat(250)
    const transaction = baseTransaction({ description: longDescription })

    const condition = ruleDefaultsFromTransaction(transaction).conditions[0]
    expect(condition.kind === 'condition' && condition.value).toHaveLength(200)
  })
})

describe('toRuleBody', () => {
  it('converte amount e account_id para número, mantém grupo e texto como string', () => {
    const values = ruleDefaults()
    values.match = 'any'
    values.conditions = [
      { kind: 'condition', field: 'amount', op: 'gt', value: '1000' },
      {
        kind: 'group',
        match: 'all',
        conditions: [
          { kind: 'condition', field: 'account_id', op: 'equals', value: '4' },
          { kind: 'condition', field: 'description', op: 'contains', value: 'uber' },
        ],
      },
    ]
    values.actions = [{ type: 'set_category', category_id: 9, tag_id: null, value: '' }]

    expect(toRuleBody(values)).toEqual({
      match: 'any',
      conditions: [
        { field: 'amount', op: 'gt', value: 1000 },
        {
          match: 'all',
          conditions: [
            { field: 'account_id', op: 'equals', value: 4 },
            { field: 'description', op: 'contains', value: 'uber' },
          ],
        },
      ],
      actions: [{ type: 'set_category', category_id: 9 }],
    })
  })

  it('mantém só as chaves de cada tipo de ação', () => {
    const values = ruleDefaults()
    values.actions = [
      { type: 'set_description', category_id: 9, tag_id: 2, value: '  nova descrição  ' },
      { type: 'ignore', category_id: 9, tag_id: 2, value: 'lixo' },
    ]

    expect(toRuleBody(values).actions).toEqual([{ type: 'set_description', value: 'nova descrição' }, { type: 'ignore' }])
  })
})

describe('ruleSchema', () => {
  it('exige valor em condições (inclusive dentro de grupo) e seleção em ações que pedem', () => {
    const values = ruleDefaults()
    values.conditions = [
      { kind: 'condition', field: 'description', op: 'contains', value: '' },
      { kind: 'group', match: 'all', conditions: [{ kind: 'condition', field: 'amount', op: 'gt', value: '' }] },
    ]
    values.actions = [{ type: 'add_tag', category_id: null, tag_id: null, value: '' }]

    const result = ruleSchema.safeParse(values)

    expect(result.success).toBe(false)
    if (result.success) return
    const paths = result.error.issues.map((issue) => issue.path.join('.'))
    expect(paths).toContain('conditions.0.value')
    expect(paths).toContain('conditions.1.conditions.0.value')
    expect(paths).toContain('actions.0.tag_id')
  })

  it('aceita um formulário completo', () => {
    const values = ruleDefaults()
    values.name = 'Uber'
    values.conditions = [{ kind: 'condition', field: 'description', op: 'contains', value: 'uber' }]
    values.actions = [{ type: 'set_category', category_id: 3, tag_id: null, value: '' }]

    expect(ruleSchema.safeParse(values).success).toBe(true)
  })
})

describe('rulePreviewSchema', () => {
  it('aceita sem nome: a prévia não depende dele', () => {
    const values = ruleDefaults()
    values.conditions = [{ kind: 'condition', field: 'description', op: 'contains', value: 'uber' }]
    values.actions = [{ type: 'set_category', category_id: 3, tag_id: null, value: '' }]

    expect(values.name).toBe('')
    expect(rulePreviewSchema.safeParse(values).success).toBe(true)
  })

  it('ainda exige valor nas condições', () => {
    const values = ruleDefaults()

    expect(rulePreviewSchema.safeParse(values).success).toBe(false)
  })
})
