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
  return { matched: 5, changed: 3, uncategorized: 3, other_category: 0, already_in_category: 0, sample: [], ...overrides }
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
    const other = {
      id: 2,
      description: 'Uber Eats',
      amount: 3200,
      currency: 'BRL',
      direction: 'out',
      category_id: 4,
      category: { id: 4, parent_id: null, name: 'Lazer', icon: null, color: null },
    }
    const already = {
      id: 5,
      description: 'Uber Centro',
      amount: 2100,
      currency: 'BRL',
      direction: 'out',
      category_id: 3,
      category: { id: 3, parent_id: 1, name: 'Aplicativo', icon: null, color: null },
    }
    const noChange = { category_id: null, description: null, payee: null, tag_ids: [], is_ignored: false }

    useRulePreviewMock.mockReturnValue(
      idleQueryResult(
        preview({
          matched: 6,
          uncategorized: 3,
          other_category: 2,
          already_in_category: 1,
          sample: [
            {
              transaction: uncategorized as unknown as Transaction,
              would_change: true,
              changes: { category_id: 3, description: null, payee: null, tag_ids: [2], is_ignored: false },
            },
            { transaction: other as unknown as Transaction, would_change: false, changes: noChange },
            { transaction: already as unknown as Transaction, would_change: false, changes: noChange },
          ],
        }),
      ),
    )

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('Lançamentos que esta regra pega')).toBeInTheDocument()
    expect(screen.getByText('6 lançamentos já existentes se encaixam nesta regra')).toBeInTheDocument()
    expect(screen.getByText(/3 sem categoria vão para/)).toHaveTextContent('3 sem categoria vão para Transporte › Aplicativo')
    expect(screen.getByText(/2 têm outra categoria e não mudam/)).toHaveTextContent('2 têm outra categoria e não mudam (use Aplicar às existentes)')
    expect(screen.getByText(/1 já está em/)).toHaveTextContent('1 já está em Transporte › Aplicativo')
    expect(screen.getByText('sem categoria → Transporte › Aplicativo')).toBeInTheDocument()
    expect(screen.getByText('Lazer (não muda)')).toBeInTheDocument()
    expect(screen.getByText('já em Transporte › Aplicativo')).toBeInTheDocument()
    expect(screen.getByText('+#viagem')).toBeInTheDocument()
  })

  it('esconde as linhas com contagem zero e usa o singular', () => {
    useRulePreviewMock.mockReturnValue(idleQueryResult(preview({ matched: 1, uncategorized: 1, other_category: 0, already_in_category: 0 })))

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('1 lançamento já existente se encaixa nesta regra')).toBeInTheDocument()
    expect(screen.getByText(/1 sem categoria vai para/)).toHaveTextContent('1 sem categoria vai para Transporte › Aplicativo')
    expect(screen.queryByText(/outra categoria/)).not.toBeInTheDocument()
    expect(screen.queryByText(/já est/)).not.toBeInTheDocument()
  })

  it('regra sem ação de categoria não mostra o detalhe por categoria', () => {
    const values = validValues()
    values.actions = [{ type: 'set_payee', category_id: null, tag_id: null, value: 'Uber' }]
    useRulePreviewMock.mockReturnValue(idleQueryResult(preview({ matched: 4, uncategorized: 0, other_category: 0 })))

    render(<Wrapper initialValues={values} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('4 lançamentos já existentes se encaixam nesta regra')).toBeInTheDocument()
    expect(screen.queryByText(/sem categoria v/)).not.toBeInTheDocument()
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
          sample: [{ transaction, would_change: true, changes: { category_id: null, description: null, payee: null, tag_ids: [999], is_ignored: false } }],
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
