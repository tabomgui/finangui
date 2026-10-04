import { act, fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
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
  useCategories: () => ({ data: [{ id: 1, name: 'Transporte' }], isPending: categoriesPending }),
}))

vi.mock('@/api/queries/tags', () => ({
  useTags: () => ({ data: [{ id: 2, name: 'viagem' }], isPending: tagsPending }),
}))

function Wrapper({ initialValues }: { initialValues: RuleFormValues }) {
  const form = useForm<RuleFormValues>({ defaultValues: initialValues })
  const [overwrite, setOverwrite] = useState(false)
  return <RulePreviewCard form={form} overwrite={overwrite} onOverwriteChange={setOverwrite} />
}

function validValues(): RuleFormValues {
  const values = ruleDefaults()
  values.name = 'Uber'
  values.conditions = [{ kind: 'condition', field: 'description', op: 'contains', value: 'uber' }]
  values.actions = [{ type: 'set_category', category_id: 1, tag_id: null, value: '' }]
  return values
}

function preview(overrides: Partial<RulePreview> = {}): RulePreview {
  return { matched: 5, changed: 3, sample: [], ...overrides }
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

  it('mostra contagens e mudanças traduzidas', () => {
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
          sample: [
            {
              transaction,
              changes: { category_id: 1, description: null, payee: null, tag_ids: [2], is_ignored: false },
            },
          ],
        }),
      ),
    )

    render(<Wrapper initialValues={validValues()} />)

    act(() => {
      vi.advanceTimersByTime(900)
    })

    expect(screen.getByText('Casa 5 lançamentos · mudaria 3 lançamentos')).toBeInTheDocument()
    expect(screen.getByText('Categoria → Transporte')).toBeInTheDocument()
    expect(screen.getByText('+#viagem')).toBeInTheDocument()
  })

  it('overwrite vai no corpo', () => {
    render(<Wrapper initialValues={validValues()} />)

    fireEvent.click(screen.getByRole('switch', { name: 'Sobrescrever categorias existentes' }))

    act(() => {
      vi.advanceTimersByTime(900)
    })

    const lastCall = useRulePreviewMock.mock.calls.at(-1)
    expect(lastCall?.[0]).toMatchObject({ overwrite: true })
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
