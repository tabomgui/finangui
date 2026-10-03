import { describe, expect, it } from 'vitest'
import type { Rule } from '@/api/types'
import { describeRule, type RuleLookups } from './rule-labels'

const lookups: RuleLookups = {
  categoryName: (id) => ({ 1: 'Transporte' } as Record<number, string>)[id],
  tagName: (id) => ({ 1: 'viagem' } as Record<number, string>)[id],
  accountName: (id) => ({ 1: 'Nubank' } as Record<number, string>)[id],
  loading: false,
}

const loadingLookups: RuleLookups = { ...lookups, categoryName: () => undefined, tagName: () => undefined, accountName: () => undefined, loading: true }

function baseRule(overrides: Partial<Rule>): Rule {
  return {
    id: 1,
    name: 'Regra',
    priority: 1,
    is_active: true,
    match: 'all',
    conditions: [],
    actions: [],
    last_applied_at: null,
    last_applied_changes: null,
    ...overrides,
  }
}

describe('describeRule', () => {
  it('descreve condição simples, categoria e tag', () => {
    const rule = baseRule({
      conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
      actions: [
        { type: 'set_category', category_id: 1 },
        { type: 'add_tag', tag_id: 1 },
      ],
    })

    expect(describeRule(rule, lookups)).toBe('Descrição contém “uber” → Transporte · +#viagem')
  })

  it('usa "ou" quando o casamento da regra é any', () => {
    const rule = baseRule({
      match: 'any',
      conditions: [
        { field: 'description', op: 'contains', value: 'uber' },
        { field: 'payee', op: 'equals', value: 'Uber' },
      ],
      actions: [{ type: 'ignore' }],
    })

    expect(describeRule(rule, lookups)).toBe('Descrição contém “uber” ou Favorecido é igual a “Uber” → ignorar')
  })

  it('descreve um grupo de condições de um nível', () => {
    const rule = baseRule({
      conditions: [
        {
          match: 'any',
          conditions: [
            { field: 'description', op: 'contains', value: 'uber' },
            { field: 'description', op: 'contains', value: '99' },
          ],
        },
      ],
      actions: [{ type: 'set_category', category_id: 1 }],
    })

    expect(describeRule(rule, lookups)).toBe('(Descrição contém “uber” ou Descrição contém “99”) → Transporte')
  })

  it('mostra "categoria excluída" quando a categoria da ação não existe mais', () => {
    const rule = baseRule({
      conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
      actions: [{ type: 'set_category', category_id: 999 }],
    })

    expect(describeRule(rule, lookups)).toBe('Descrição contém “uber” → categoria excluída')
  })

  it('não mostra "categoria excluída" enquanto as categorias ainda não carregaram', () => {
    const rule = baseRule({
      conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
      actions: [{ type: 'set_category', category_id: 1 }],
    })

    expect(describeRule(rule, loadingLookups)).toBe('Descrição contém “uber” → …')
  })

  it('descreve uma condição de conta pelo nome, com "conta excluída" quando não existe mais', () => {
    const matched = baseRule({ conditions: [{ field: 'account_id', op: 'equals', value: 1 }], actions: [{ type: 'ignore' }] })
    const missing = baseRule({ conditions: [{ field: 'account_id', op: 'equals', value: 999 }], actions: [{ type: 'ignore' }] })

    expect(describeRule(matched, lookups)).toBe('Conta é igual a Nubank → ignorar')
    expect(describeRule(missing, lookups)).toBe('Conta é igual a conta excluída → ignorar')
  })

  it('formata valor de amount como dinheiro', () => {
    const rule = baseRule({ conditions: [{ field: 'amount', op: 'gte', value: 15000 }], actions: [{ type: 'ignore' }] })

    expect(describeRule(rule, lookups)).toBe('Valor maior ou igual a R$ 150,00 → ignorar')
  })

  it('formata valor de date como dd/mm/aaaa', () => {
    const rule = baseRule({ conditions: [{ field: 'date', op: 'equals', value: '2026-01-15' }], actions: [{ type: 'ignore' }] })

    expect(describeRule(rule, lookups)).toBe('Data é igual a 15/01/2026 → ignorar')
  })

  it('traduz o valor de direction para Entrada/Saída', () => {
    const income = baseRule({ conditions: [{ field: 'direction', op: 'equals', value: 'in' }], actions: [{ type: 'ignore' }] })
    const expense = baseRule({ conditions: [{ field: 'direction', op: 'equals', value: 'out' }], actions: [{ type: 'ignore' }] })

    expect(describeRule(income, lookups)).toBe('Tipo é igual a Entrada → ignorar')
    expect(describeRule(expense, lookups)).toBe('Tipo é igual a Saída → ignorar')
  })
})
