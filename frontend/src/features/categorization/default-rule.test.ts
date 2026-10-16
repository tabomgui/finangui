import { describe, expect, it } from 'vitest'
import {
  condition,
  countRuleMatches,
  defaultAlternatives,
  defaultRuleName,
  needsServerPreview,
  normalizeRuleText,
  toRuleConditions,
  type MatchableTransaction,
  type RuleAlternative,
} from './default-rule'

function tx(overrides: Partial<MatchableTransaction>): MatchableTransaction {
  return { id: 1, description: 'Pix enviado', original_description: 'Pix enviado', direction: 'out', ...overrides }
}

function alternative(...conditions: RuleAlternative['conditions']): RuleAlternative {
  return { uid: 'alt', conditions }
}

describe('defaultAlternatives', () => {
  it('gera uma alternativa por grupo, com o termo na descrição original e a direção da seleção', () => {
    const alternatives = defaultAlternatives(['Shpp Brasil', 'Uber'], 'out')

    expect(toRuleConditions(alternatives)).toEqual([
      {
        match: 'all',
        conditions: [
          { field: 'original_description', op: 'contains', value: 'Shpp Brasil' },
          { field: 'direction', op: 'equals', value: 'out' },
        ],
      },
      {
        match: 'all',
        conditions: [
          { field: 'original_description', op: 'contains', value: 'Uber' },
          { field: 'direction', op: 'equals', value: 'out' },
        ],
      },
    ])
  })

  it('usa a direção de entrada quando a seleção é de entradas', () => {
    const [only] = defaultAlternatives(['Salário ACME'], 'in')
    expect(only.conditions[1]).toMatchObject({ field: 'direction', op: 'equals', value: 'in' })
  })

  it('corta o termo no limite de texto de condição', () => {
    const [only] = defaultAlternatives(['x'.repeat(250)], 'out')
    expect(only.conditions[0].value).toHaveLength(200)
  })
})

describe('defaultRuleName', () => {
  it('junta o grupo e o caminho da categoria', () => {
    expect(defaultRuleName(['Shpp Brasil'], 'Compras › Shopee')).toBe('Shpp Brasil → Compras › Shopee')
  })

  it('junta vários grupos com ", "', () => {
    expect(defaultRuleName(['Shpp Brasil', 'Uber'], 'Compras')).toBe('Shpp Brasil, Uber → Compras')
  })

  it('corta em 80 caracteres', () => {
    const name = defaultRuleName(['Grupo com um nome bem comprido', 'Outro grupo também comprido', 'Mais um'], 'Compras › Shopee')
    expect(name.length).toBeLessThanOrEqual(80)
    expect(name.endsWith('…')).toBe(true)
    expect(name.startsWith('Grupo com um nome bem comprido, Outro grupo')).toBe(true)
  })
})

describe('normalizeRuleText', () => {
  it('ignora caixa, acento e espaços repetidos', () => {
    expect(normalizeRuleText('  Pão  de   Açúcar ')).toBe('PAO DE ACUCAR')
  })
})

describe('countRuleMatches', () => {
  const transactions = [
    tx({ id: 1, original_description: 'PIX ENVIADO SHPP BRASIL', description: 'Shopee' }),
    tx({ id: 2, original_description: 'Pix enviado - Shpp Brasil Instituição', description: 'Pix enviado - Shpp Brasil Instituição' }),
    tx({ id: 3, original_description: 'Pix recebido - Shpp Brasil', direction: 'in' }),
    tx({ id: 4, original_description: 'Uber trip', description: 'Uber trip' }),
  ]

  it('conta sem caixa nem acento e separa os além dos marcados', () => {
    const result = countRuleMatches(defaultAlternatives(['shpp brasil'], 'out'), transactions, new Set([1]), 'out')
    expect(result).toEqual({ kind: 'local', matched: 2, extra: 1 })
  })

  it('só conta pendentes da direção da seleção, mesmo sem condição de direção', () => {
    const rule = [alternative(condition('original_description', 'contains', 'Shpp'))]
    expect(countRuleMatches(rule, transactions, new Set(), 'out')).toEqual({ kind: 'local', matched: 2, extra: 2 })
  })

  it('qualquer alternativa basta', () => {
    const rule = defaultAlternatives(['Shpp', 'Uber'], 'out')
    expect(countRuleMatches(rule, transactions, new Set([1, 2]), 'out')).toEqual({ kind: 'local', matched: 3, extra: 1 })
  })

  it('avalia starts_with e equals sobre a descrição e a descrição original', () => {
    const startsWith = [alternative(condition('description', 'starts_with', 'pix enviado'))]
    expect(countRuleMatches(startsWith, transactions, new Set(), 'out')).toMatchObject({ matched: 1 })

    const equals = [alternative(condition('description', 'equals', 'shopee'))]
    expect(countRuleMatches(equals, transactions, new Set(), 'out')).toMatchObject({ matched: 1 })

    const originalEquals = [alternative(condition('original_description', 'equals', 'uber   TRIP'))]
    expect(countRuleMatches(originalEquals, transactions, new Set(), 'out')).toMatchObject({ matched: 1 })
  })

  it('contém com valor vazio nunca casa; não contém com valor vazio sempre casa', () => {
    expect(countRuleMatches([alternative(condition('description', 'contains', ''))], transactions, new Set(), 'out')).toMatchObject({
      matched: 0,
    })
    expect(countRuleMatches([alternative(condition('description', 'not_contains', ''))], transactions, new Set(), 'out')).toMatchObject({
      matched: 3,
    })
  })

  it('direção diferente de', () => {
    const rule = [alternative(condition('direction', 'not_equals', 'in'))]
    expect(countRuleMatches(rule, transactions, new Set(), 'out')).toMatchObject({ matched: 3 })
  })

  it('alternativa sem condição não casa', () => {
    expect(countRuleMatches([alternative()], transactions, new Set(), 'out')).toMatchObject({ matched: 0 })
  })

  it('campos que o cliente não avalia pedem a prévia do servidor', () => {
    for (const unsupported of [
      condition('amount', 'gt', '1000'),
      condition('account_id', 'equals', '3'),
      condition('date', 'gte', '2026-01-01'),
      condition('payee', 'contains', 'Fulano'),
      condition('notes', 'contains', 'x'),
      condition('description', 'regex', '^PIX'),
    ]) {
      const rule = [alternative(condition('description', 'contains', 'Pix'), unsupported)]
      expect(needsServerPreview(rule)).toBe(true)
      expect(countRuleMatches(rule, transactions, new Set(), 'out')).toEqual({ kind: 'server' })
    }
  })

  it('a regra padrão não precisa do servidor', () => {
    expect(needsServerPreview(defaultAlternatives(['Shpp'], 'out'))).toBe(false)
  })
})
