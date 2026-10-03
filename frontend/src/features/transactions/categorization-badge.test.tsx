import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { Rule } from '@/api/types'
import { TooltipProvider } from '@/components/ui/tooltip'
import { CategorizationBadge } from './categorization-badge'

let mockRules: Rule[] | undefined = []

vi.mock('@/api/queries/rules', () => ({
  useRules: () => ({ data: mockRules }),
}))

function rule(overrides: Partial<Rule>): Rule {
  return {
    id: 1,
    name: 'Uber',
    priority: 1,
    is_active: true,
    match: 'all',
    conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
    actions: [{ type: 'set_category', category_id: 1 }],
    last_applied_at: null,
    last_applied_changes: null,
    ...overrides,
  }
}

function renderBadge(categorization: Parameters<typeof CategorizationBadge>[0]['categorization']) {
  return render(
    <TooltipProvider>
      <CategorizationBadge categorization={categorization} />
    </TooltipProvider>,
  )
}

describe('CategorizationBadge', () => {
  it('não renderiza nada para manual', () => {
    const { container } = renderBadge({ source: 'manual' })
    expect(container).toBeEmptyDOMElement()
  })

  it('não renderiza nada para sem categorização', () => {
    const { container } = renderBadge(null)
    expect(container).toBeEmptyDOMElement()
  })

  it('mostra o nome da regra', () => {
    mockRules = [rule({ id: 7, name: 'Uber vira Transporte' })]
    renderBadge({ source: 'rule', rule_id: 7 })

    expect(screen.getByLabelText('Categorizada pela regra Uber vira Transporte')).toBeInTheDocument()
  })

  it('avisa que a regra foi excluída quando o id não existe mais', () => {
    mockRules = []
    renderBadge({ source: 'rule', rule_id: 99 })

    expect(screen.getByLabelText('Categorizada por uma regra excluída')).toBeInTheDocument()
  })

  it('mostra a origem histórico', () => {
    renderBadge({ source: 'history' })

    expect(screen.getByLabelText('Categorizada pelo histórico')).toBeInTheDocument()
  })

  it('mostra a origem banco (pluggy)', () => {
    renderBadge({ source: 'pluggy' })

    expect(screen.getByLabelText('Categoria informada pelo banco')).toBeInTheDocument()
  })
})
