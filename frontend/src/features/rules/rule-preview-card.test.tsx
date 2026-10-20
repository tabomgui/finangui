import { act, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { RulePreview, Transaction } from '@/api/types'
import { ruleDefaults, type RuleFormValues } from './rule-form-values'
import { RulePreviewCard } from './rule-preview-card'

const useRulePreviewMock = vi.fn()
let categoriesPending = false
let tagsPending = false

vi.mock('@/api/queries/rules', () => ({
  useRulePreview: (...args: unknown[]) => useRulePreviewMock(...args),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({
    data: [
      { id: 1, parent_id: null, name: 'Transporte' },
      { id: 3, parent_id: 1, name: 'Aplicativo' },
      { id: 4, parent_id: null, name: 'Lazer' },
    ],
    isPending: categoriesPending,
  }),
}))

vi.mock('@/api/queries/tags', () => ({
  useTags: () => ({ data: [{ id: 2, name: 'viagem' }], isPending: tagsPending }),
}))

function Wrapper({ initialValues }: { initialValues: RuleFormValues }) {
  const form = useForm<RuleFormValues>({ defaultValues: initialValues })
  return <RulePreviewCard form={form} />
}

function validValues(): RuleFormValues {
  const values = ruleDefaults()
  values.name = 'Uber'
  values.conditions = [{ kind: 'condition', field: 'description', op: 'contains', value: 'uber' }]
  values.actions = [{ type: 'set_category', category_id: 3, tag_id: null, value: '' }]
  return values
}

function preview(overrides: Partial<RulePreview> = {}): RulePreview {
  return { matched: 5, changed: 3, uncategorized: 3, other_category: 0, sample: [], ...overrides }
}

function idleQueryResult(data: RulePreview | undefined = undefined) {
  return { data, isPending: false, isPlaceholderData: false, isError: false, error: null, refetch: vi.fn() }
}

beforeEach(() => {
  vi.useFakeTimers()
  useRulePreviewMock.mockReset()
  useRulePreviewMock.mockReturnValue(idleQueryResult())
  categoriesPending = false
  tagsPending = false
})

afterEach(() => {
  vi.useRealTimers()
})

describe('RulePreviewCard', () => {
  it('não consulta com formulário inválido', () => {
    render(<Wrapper initialValues={ruleDefaults()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('Complete a regra para ver a prévia.')).toBeInTheDocument()
    expect(useRulePreviewMock).toHaveBeenLastCalledWith(expect.anything(), false)
  })

  it('mostra quantos pega, o detalhe da categoria e "atual → nova" na amostra', () => {
    const uncategorized = { id: 1, description: 'Uber', amount: 1500, currency: 'BRL', direction: 'out', category_id: null, category: null }
    const recategorized = {
      id: 2,
      description: 'Uber Eats',
      amount: 3200,
      currency: 'BRL',
      direction: 'out',
      category_id: 4,
      category: { id: 4, parent_id: null, name: 'Lazer', icon: null, color: null },
    }

    useRulePreviewMock.mockReturnValue(
      idleQueryResult(
        preview({
          matched: 5,
          uncategorized: 3,
          other_category: 2,
          sample: [
            {
              transaction: uncategorized as unknown as Transaction,
              changes: { category_id: 3, description: null, payee: null, tag_ids: [2], is_ignored: false },
            },
            {
              transaction: recategorized as unknown as Transaction,
              changes: { category_id: 3, description: null, payee: null, tag_ids: [], is_ignored: false },
            },
          ],
        }),
      ),
    )

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('Lançamentos que esta regra pega')).toBeInTheDocument()
    expect(screen.getByText('Pega 5 lançamentos hoje')).toBeInTheDocument()
    expect(screen.getByText(/3 sem categoria recebem/)).toHaveTextContent('3 sem categoria recebem Transporte › Aplicativo')
    expect(screen.getByText('2 já têm outra categoria')).toBeInTheDocument()
    expect(screen.getByText('sem categoria → Transporte › Aplicativo')).toBeInTheDocument()
    expect(screen.getByText('Lazer → Transporte › Aplicativo')).toBeInTheDocument()
    expect(screen.getByText('+#viagem')).toBeInTheDocument()
  })

  it('no singular: "1 sem categoria recebe" e "1 já tem outra categoria"', () => {
    useRulePreviewMock.mockReturnValue(idleQueryResult(preview({ matched: 2, uncategorized: 1, other_category: 1 })))

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText(/1 sem categoria recebe$/)).toHaveTextContent('1 sem categoria recebe Transporte › Aplicativo')
    expect(screen.getByText('1 já tem outra categoria')).toBeInTheDocument()
  })

  it('regra sem ação de categoria não mostra o detalhe por categoria', () => {
    const values = validValues()
    values.actions = [{ type: 'set_payee', category_id: null, tag_id: null, value: 'Uber' }]
    useRulePreviewMock.mockReturnValue(idleQueryResult(preview({ matched: 4, uncategorized: 0, other_category: 0 })))

    render(<Wrapper initialValues={values} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('Pega 4 lançamentos hoje')).toBeInTheDocument()
    expect(screen.queryByText(/sem categoria receb/)).not.toBeInTheDocument()
    expect(screen.queryByText(/outra categoria/)).not.toBeInTheDocument()
  })

  it('não tem mais o switch de sobrescrever, e a prévia vai sem overwrite', () => {
    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.queryByRole('switch')).not.toBeInTheDocument()
    expect(screen.queryByText('Sobrescrever categorias existentes')).not.toBeInTheDocument()
    expect(screen.queryByText('Categorias definidas à mão nunca são sobrescritas.')).not.toBeInTheDocument()
    const lastCall = useRulePreviewMock.mock.calls.at(-1)
    expect(lastCall?.[0]).not.toHaveProperty('overwrite')
    expect(lastCall?.[1]).toBe(true)
  })

  it('tag apagada mostra "excluída", mas enquanto a lista de tags carrega mostra o placeholder', () => {
    const transaction = {
      id: 1,
      description: 'Uber',
      amount: 1500,
      currency: 'BRL',
      direction: 'out',
      category: null,
    } as unknown as Transaction

    useRulePreviewMock.mockReturnValue(
      idleQueryResult(
        preview({
          sample: [{ transaction, changes: { category_id: null, description: null, payee: null, tag_ids: [999], is_ignored: false } }],
        }),
      ),
    )
    tagsPending = true

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('+#…')).toBeInTheDocument()
  })

  it('mostra o aviso sobre lançamentos ignorados quando a regra tem a ação ignore', () => {
    const values = validValues()
    values.actions = [...values.actions, { type: 'ignore', category_id: null, tag_id: null, value: '' }]
    render(<Wrapper initialValues={values} />)

    expect(screen.getByText('Lançamentos ignorados saem do saldo, dos relatórios e do total das faturas.')).toBeInTheDocument()
  })

  it('não mostra o aviso de ignorar quando a regra não tem essa ação', () => {
    render(<Wrapper initialValues={validValues()} />)

    expect(screen.queryByText('Lançamentos ignorados saem do saldo, dos relatórios e do total das faturas.')).not.toBeInTheDocument()
  })

  it('429 mostra mensagem calma de prévia, não o texto padrão de erro', () => {
    useRulePreviewMock.mockReturnValue({
      data: undefined,
      isPending: false,
      isPlaceholderData: false,
      isError: true,
      error: new ApiError(429, 'Muitas tentativas. Aguarde um minuto e tente de novo.'),
      refetch: vi.fn(),
    })

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('Muitas prévias seguidas; aguarde um instante.')).toBeInTheDocument()
  })
})
