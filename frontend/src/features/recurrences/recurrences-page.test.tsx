import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Recurrence } from '@/api/types'
import { RecurrencesPage } from './recurrences-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const refetch = vi.fn()
const updateMutateAsync = vi.fn()
const deleteMutateAsync = vi.fn()

let recurrencesState: { data: Recurrence[] | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/recurrences', () => ({
  useRecurrences: () => ({ ...recurrencesState, refetch }),
  useUpdateRecurrence: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
  useDeleteRecurrence: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
  useCreateRecurrence: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [{ id: 1, name: 'Nubank' }] }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({ data: [{ id: 2, name: 'Moradia', kind: 'expense', is_archived: false }] }),
}))

function recurrence(overrides: Partial<Recurrence> = {}): Recurrence {
  return {
    id: 1,
    account_id: 1,
    account: { id: 1, name: 'Nubank' },
    category_id: 2,
    category: { id: 2, name: 'Moradia', icon: 'house', color: null },
    description: 'Aluguel',
    amount: 150000,
    direction: 'out',
    frequency: 'monthly',
    interval: 1,
    day_of_month: 10,
    starts_on: '2026-01-10',
    ends_on: null,
    generated_until: null,
    match_pattern: null,
    is_active: true,
    next_date: '2026-11-10',
    ...overrides,
  }
}

function renderPage() {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <RecurrencesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  refetch.mockReset()
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteMutateAsync.mockReset().mockResolvedValue(undefined)
  recurrencesState = { data: undefined, isPending: true, isError: false }
})

describe('RecurrencesPage', () => {
  it('mostra skeletons enquanto carrega', () => {
    const { container } = renderPage()
    expect(screen.queryByText('Nenhuma recorrência ainda')).not.toBeInTheDocument()
    expect(container.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(3)
  })

  it('lista as recorrências', () => {
    recurrencesState = { data: [recurrence()], isPending: false, isError: false }
    renderPage()

    expect(screen.getByText('Aluguel')).toBeInTheDocument()
  })

  it('mostra o estado vazio quando não há recorrências', () => {
    recurrencesState = { data: [], isPending: false, isError: false }
    renderPage()

    expect(screen.getByText('Nenhuma recorrência ainda')).toBeInTheDocument()
  })

  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    recurrencesState = { data: undefined, isPending: false, isError: true }
    renderPage()

    expect(screen.getByText('Não foi possível carregar as recorrências.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('botão "Nova recorrência" abre o diálogo de criação', () => {
    recurrencesState = { data: [], isPending: false, isError: false }
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Nova recorrência' }))

    expect(screen.getByText('Nova recorrência', { selector: '[data-slot="dialog-title"]' })).toBeInTheDocument()
  })

  it('editar pelo menu da linha abre o diálogo preenchido', async () => {
    recurrencesState = { data: [recurrence()], isPending: false, isError: false }
    renderPage()

    const trigger = screen.getByRole('button', { name: 'Ações da recorrência Aluguel' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Editar' }))

    expect(screen.getByText('Editar recorrência', { selector: '[data-slot="dialog-title"]' })).toBeInTheDocument()
  })
})
