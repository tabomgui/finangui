import { describe, expect, it } from 'vitest'
import type { Rule } from '@/api/types'
import { describeRule, reorderIds } from './rule-labels'

const lookups = {
  categoryName: (id: number) => ({ 1: 'Transporte' } as Record<number, string>)[id],
  tagName: (id: number) => ({ 1: 'viagem' } as Record<number, string>)[id],
}

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

    expect(describeRule(rule, lookups)).toBe('Descrição contém "uber" → Transporte · +#viagem')
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

    expect(describeRule(rule, lookups)).toBe('Descrição contém "uber" ou Favorecido é igual a "Uber" → ignorar')
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

    expect(describeRule(rule, lookups)).toBe('(Descrição contém "uber" ou Descrição contém "99") → Transporte')
  })

  it('mostra "categoria excluída" quando a categoria da ação não existe mais', () => {
    const rule = baseRule({
      conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
      actions: [{ type: 'set_category', category_id: 999 }],
    })

    expect(describeRule(rule, lookups)).toBe('Descrição contém "uber" → categoria excluída')
  })
})

describe('reorderIds', () => {
  it('move o id ativo para a posição do id sobre o qual foi soltado', () => {
    expect(reorderIds([1, 2, 3, 4], 1, 3)).toEqual([2, 3, 1, 4])
  })

  it('não muda a lista se o id ativo ou o de destino não existir', () => {
    expect(reorderIds([1, 2, 3], 9, 2)).toEqual([1, 2, 3])
  })
})
