import { act, fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { RulePreview, Transaction } from '@/api/types'
import { ruleDefaults, type RuleFormValues } from './rule-form-values'
import { RulePreviewCard } from './rule-preview-card'

const useRulePreviewMock = vi.fn()

vi.mock('@/api/queries/rules', () => ({
  useRulePreview: (...args: unknown[]) => useRulePreviewMock(...args),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({ data: [{ id: 1, name: 'Transporte' }] }),
}))

vi.mock('@/api/queries/tags', () => ({
  useTags: () => ({ data: [{ id: 2, name: 'viagem' }] }),
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
})

afterEach(() => {
  vi.useRealTimers()
})

describe('RulePreviewCard', () => {
  it('não consulta com formulário inválido', () => {
    render(<Wrapper initialValues={ruleDefaults()} />)

    act(() => {
      vi.advanceTimersByTime(600)
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
      vi.advanceTimersByTime(600)
    })

    expect(screen.getByText('Casa 5 lançamentos · mudaria 3 lançamentos')).toBeInTheDocument()
    expect(screen.getByText('Categoria → Transporte')).toBeInTheDocument()
    expect(screen.getByText('+#viagem')).toBeInTheDocument()
  })

  it('overwrite vai no corpo', () => {
    render(<Wrapper initialValues={validValues()} />)

    fireEvent.click(screen.getByRole('switch', { name: 'Sobrescrever categorias existentes' }))

    act(() => {
      vi.advanceTimersByTime(600)
    })

    const lastCall = useRulePreviewMock.mock.calls.at(-1)
    expect(lastCall?.[0]).toMatchObject({ overwrite: true })
    expect(lastCall?.[1]).toBe(true)
  })
})
