import { describe, expect, it } from 'vitest'
import { condition, defaultAlternatives, MAX_DEFAULT_RULE_GROUPS, MAX_RULE_CONDITIONS, ruleConditionCount, type RuleAlternative } from './default-rule'
import { rulePreviewBody } from './use-rule-match-count'

function alternative(...conditions: RuleAlternative['conditions']): RuleAlternative {
  return { uid: 'alt', conditions }
}

const hasDirection = (body: ReturnType<typeof rulePreviewBody>) => JSON.stringify(body.conditions).includes('"direction"')

describe('rulePreviewBody', () => {
  it('acrescenta a direção da seleção quando a regra não tem', () => {
    const body = rulePreviewBody([alternative(condition('description', 'regex', '^PIX'))], 'out', 50)

    expect(body).toEqual({
      match: 'all',
      conditions: [
        { field: 'description', op: 'regex', value: '^PIX' },
        { field: 'direction', op: 'equals', value: 'out' },
      ],
      actions: [{ type: 'set_category', category_id: 50 }],
      overwrite: false,
    })
  })

  it('a regra padrão no limite de grupos continua dentro do limite de condições', () => {
    const names = Array.from({ length: MAX_DEFAULT_RULE_GROUPS }, (_, i) => `Grupo ${i + 1}`)
    const body = rulePreviewBody(defaultAlternatives(names, 'out'), 'out', 50)

    expect(ruleConditionCount(body.conditions)).toBe(MAX_RULE_CONDITIONS)
  })

  it('não acrescenta a direção quando isso passaria do limite', () => {
    // Dez alternativas de duas condições sem nada em comum: 20 condições, já no limite.
    const alternatives = Array.from({ length: 10 }, (_, i) =>
      alternative(condition('description', 'contains', `Termo ${i}`), condition('amount', 'gt', String(i + 1))),
    )
    const body = rulePreviewBody(alternatives, 'out', 50)

    expect(ruleConditionCount(body.conditions)).toBeLessThanOrEqual(MAX_RULE_CONDITIONS)
    expect(hasDirection(body)).toBe(false)
  })
})
