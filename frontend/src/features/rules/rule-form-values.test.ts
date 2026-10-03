import { describe, expect, it } from 'vitest'
import type { Rule, Transaction } from '@/api/types'
import { ruleDefaults, ruleDefaultsFromTransaction, ruleSchema, toRuleBody } from './rule-form-values'

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

    expect(defaults).toEqual({
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

    expect(defaults.conditions).toEqual([
      { kind: 'condition', field: 'description', op: 'contains', value: 'uber' },
      { kind: 'group', match: 'any', conditions: [{ kind: 'condition', field: 'amount', op: 'gt', value: '1000' }] },
    ])
    expect(defaults.actions).toEqual([
      { type: 'set_category', category_id: 3, tag_id: null, value: '' },
      { type: 'add_tag', category_id: null, tag_id: 7, value: '' },
    ])
  })
})

describe('ruleDefaultsFromTransaction', () => {
  it('com categoria: condição pela descrição e ação pela categoria', () => {
    const transaction = baseTransaction({ description: 'Uber viagem', category_id: 5 })

    expect(ruleDefaultsFromTransaction(transaction)).toEqual({
      name: 'Uber viagem',
      is_active: true,
      match: 'all',
      conditions: [{ kind: 'condition', field: 'description', op: 'contains', value: 'Uber viagem' }],
      actions: [{ type: 'set_category', category_id: 5, tag_id: null, value: '' }],
    })
  })

  it('sem categoria: ação de categoria fica vazia', () => {
    const transaction = baseTransaction({ description: 'Mercado', category_id: null })

    expect(ruleDefaultsFromTransaction(transaction).actions).toEqual([
      { type: 'set_category', category_id: null, tag_id: null, value: '' },
    ])
  })

  it('trunca o nome em 80 caracteres', () => {
    const longDescription = 'x'.repeat(120)
    const transaction = baseTransaction({ description: longDescription })

    expect(ruleDefaultsFromTransaction(transaction).name).toHaveLength(80)
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
