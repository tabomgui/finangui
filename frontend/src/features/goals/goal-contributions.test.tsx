import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { GoalContribution } from '@/api/types'
import { GoalContributions } from './goal-contributions'

const refetch = vi.fn()
const deleteMutateAsync = vi.fn()
const createMutateAsync = vi.fn()

let contributionsState: { data: GoalContribution[] | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/goals', () => ({
  useGoalContributions: () => ({ ...contributionsState, refetch }),
  useDeleteGoalContribution: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
  useCreateGoalContribution: () => ({ mutateAsync: createMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function contribution(overrides: Partial<GoalContribution> = {}): GoalContribution {
  return { id: 1, amount: 20000, date: '2026-10-01', note: null, ...overrides }
}

beforeEach(() => {
  refetch.mockReset()
  deleteMutateAsync.mockReset().mockResolvedValue(undefined)
  createMutateAsync.mockReset()
  contributionsState = { data: [], isPending: false, isError: false }
})

describe('GoalContributions', () => {
  it('mostra o estado vazio quando não há aportes', () => {
    render(<GoalContributions goalId={1} currency="BRL" />)

    expect(screen.getByText('Nenhum aporte registrado ainda.')).toBeInTheDocument()
  })

  it('mostra erro com botão "Tentar de novo"', () => {
    contributionsState = { data: undefined, isPending: false, isError: true }
    render(<GoalContributions goalId={1} currency="BRL" />)

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('lista os aportes com data, nota e valor', () => {
    contributionsState = { data: [contribution({ note: 'Bônus' })], isPending: false, isError: false }
    render(<GoalContributions goalId={1} currency="BRL" />)

    expect(screen.getByText(/Bônus/)).toBeInTheDocument()
    expect(screen.getByText('R$ 200,00')).toBeInTheDocument()
  })

  it('remove um aporte ao confirmar', async () => {
    contributionsState = { data: [contribution()], isPending: false, isError: false }
    render(<GoalContributions goalId={1} currency="BRL" />)

    fireEvent.click(screen.getByRole('button', { name: /Remover lançamento/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Remover' }))

    await waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith(1))
  })

  it('abre o diálogo de novo aporte', () => {
    render(<GoalContributions goalId={1} currency="BRL" />)

    fireEvent.click(screen.getByRole('button', { name: 'Novo aporte ou retirada' }))

    expect(screen.getByText('Registre quanto guardou ou retirou desta meta.')).toBeInTheDocument()
  })
})
