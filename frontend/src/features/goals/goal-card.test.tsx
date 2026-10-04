import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Goal } from '@/api/types'
import { GoalCard } from './goal-card'

const deleteMutateAsync = vi.fn()
const createContributionMutateAsync = vi.fn()
const deleteContributionMutateAsync = vi.fn()

let contributionsState: { data: unknown[] | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/goals', () => ({
  useDeleteGoal: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
  useGoalContributions: () => ({ ...contributionsState, refetch: vi.fn() }),
  useCreateGoalContribution: () => ({ mutateAsync: createContributionMutateAsync, isPending: false }),
  useDeleteGoalContribution: () => ({ mutateAsync: deleteContributionMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function goal(overrides: Partial<Goal> = {}): Goal {
  return {
    id: 1,
    name: 'Viagem',
    target_amount: 500000,
    target_date: null,
    account_id: null,
    color: null,
    icon: null,
    achieved_at: null,
    progress: 100000,
    remaining: 400000,
    percent: 20,
    ...overrides,
  }
}

async function openMenu(name: string | RegExp) {
  const trigger = screen.getByRole('button', { name })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
}

beforeEach(() => {
  deleteMutateAsync.mockReset().mockResolvedValue(undefined)
  createContributionMutateAsync.mockReset()
  deleteContributionMutateAsync.mockReset()
  contributionsState = { data: [], isPending: false, isError: false }
})

describe('GoalCard', () => {
  it('mostra nome, progresso, quanto falta e o percentual', () => {
    render(<GoalCard goal={goal()} currency="BRL" onEdit={vi.fn()} />)

    expect(screen.getByText('Viagem')).toBeInTheDocument()
    expect(screen.getByText('R$ 1.000,00')).toBeInTheDocument()
    expect(screen.getByText('R$ 5.000,00')).toBeInTheDocument()
    expect(screen.getByText('R$ 4.000,00')).toBeInTheDocument()
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '20')
  })

  it('mostra o selo "Atingida" e esconde "Faltam" quando a meta foi atingida', () => {
    render(<GoalCard goal={goal({ achieved_at: '2026-01-01T00:00:00Z', percent: 100, remaining: 0 })} currency="BRL" onEdit={vi.fn()} />)

    expect(screen.getByText('Atingida')).toBeInTheDocument()
    expect(screen.queryByText(/Faltam/)).not.toBeInTheDocument()
  })

  it('mostra quanto guardar por mês quando há data-limite', () => {
    render(<GoalCard goal={goal({ target_date: '2026-12-01', monthly_needed: 50000 })} currency="BRL" onEdit={vi.fn()} />)

    expect(screen.getByText(/Guarde/)).toBeInTheDocument()
    expect(screen.getByText('R$ 500,00')).toBeInTheDocument()
    expect(screen.getByText(/01\/12\/2026/)).toBeInTheDocument()
  })

  it('com conta vinculada, mostra o nome da conta e não oferece aportes', () => {
    render(<GoalCard goal={goal({ account_id: 3, account: { id: 3, name: 'Poupança' } })} currency="BRL" onEdit={vi.fn()} />)

    expect(screen.getByText('Saldo de Poupança')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Aportes' })).not.toBeInTheDocument()
  })

  it('sem conta, expande a lista de aportes ao clicar em "Aportes"', () => {
    render(<GoalCard goal={goal()} currency="BRL" onEdit={vi.fn()} />)

    expect(screen.queryByText('Nenhum aporte registrado ainda.')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Aportes' }))

    expect(screen.getByText('Nenhum aporte registrado ainda.')).toBeInTheDocument()
  })

  it('chama onEdit ao escolher "Editar"', async () => {
    const onEdit = vi.fn()
    render(<GoalCard goal={goal()} currency="BRL" onEdit={onEdit} />)

    await openMenu(/Ações da meta/)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Editar' }))

    expect(onEdit).toHaveBeenCalled()
  })

  it('remove a meta ao confirmar "Remover"', async () => {
    render(<GoalCard goal={goal()} currency="BRL" onEdit={vi.fn()} />)

    await openMenu(/Ações da meta/)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Remover' }))
    fireEvent.click(screen.getByRole('button', { name: 'Remover' }))

    await waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith(1))
  })
})
