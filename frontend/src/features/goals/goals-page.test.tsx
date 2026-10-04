import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Goal } from '@/api/types'
import { GoalsPage } from './goals-page'

const refetch = vi.fn()
const deleteMutateAsync = vi.fn()

let goalsState: { data: Goal[] | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/goals', () => ({
  useGoals: () => ({ ...goalsState, refetch }),
  useDeleteGoal: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
  useCreateGoal: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateGoal: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/auth', () => ({
  useMe: () => ({ data: { primary_currency: 'BRL' } }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
  useNotifications: () => ({ data: undefined, fetchNextPage: vi.fn(), hasNextPage: false, isFetchingNextPage: false, isPending: true }),
  useMarkRead: () => ({ mutate: vi.fn() }),
  useMarkAllRead: () => ({ mutate: vi.fn(), isPending: false }),
}))

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

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/metas']}>
      <GoalsPage />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  refetch.mockReset()
  deleteMutateAsync.mockReset()
  goalsState = { data: undefined, isPending: true, isError: false }
})

describe('GoalsPage', () => {
  it('mostra esqueletos de carregamento', () => {
    renderPage()

    expect(screen.queryByText('Viagem')).not.toBeInTheDocument()
  })

  it('mostra erro com botão "Tentar de novo"', () => {
    goalsState = { data: undefined, isPending: false, isError: true }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('sem metas, mostra o estado vazio', () => {
    goalsState = { data: [], isPending: false, isError: false }
    renderPage()

    expect(screen.getByText('Nenhuma meta ainda')).toBeInTheDocument()
  })

  it('lista as metas', () => {
    goalsState = { data: [goal()], isPending: false, isError: false }
    renderPage()

    expect(screen.getByText('Viagem')).toBeInTheDocument()
  })

  it('abre o diálogo de nova meta', () => {
    goalsState = { data: [goal()], isPending: false, isError: false }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Nova meta' }))

    expect(screen.getByText('Defina quanto quer guardar e, se quiser, até quando.')).toBeInTheDocument()
  })
})
