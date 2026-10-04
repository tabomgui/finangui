import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Recurrence } from '@/api/types'
import { RecurrenceRow } from './recurrence-row'

const updateMutateAsync = vi.fn()
const deleteMutateAsync = vi.fn()

vi.mock('@/api/queries/recurrences', () => ({
  useUpdateRecurrence: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
  useDeleteRecurrence: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

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

function renderRow(props: Partial<Parameters<typeof RecurrenceRow>[0]> = {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <RecurrenceRow recurrence={recurrence()} onEdit={vi.fn()} {...props} />
    </QueryClientProvider>,
  )
}

async function openMenu(name: string) {
  const trigger = screen.getByRole('button', { name })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
}

beforeEach(() => {
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteMutateAsync.mockReset().mockResolvedValue(undefined)
})

describe('RecurrenceRow', () => {
  it('mostra descrição, frequência, conta, categoria, valor e próxima data', () => {
    renderRow()

    expect(screen.getByText('Aluguel')).toBeInTheDocument()
    expect(screen.getByText(/Todo mês, dia 10/)).toBeInTheDocument()
    expect(screen.getByText(/Nubank/)).toBeInTheDocument()
    expect(screen.getByText(/Moradia/)).toBeInTheDocument()
    expect(screen.getByText(/1\.500,00/)).toBeInTheDocument()
    expect(screen.getByText(/10\/11\/2026/)).toBeInTheDocument()
  })

  it('mostra badge "Pausada" quando is_active é falso', () => {
    renderRow({ recurrence: recurrence({ is_active: false }) })

    expect(screen.getByText('Pausada')).toBeInTheDocument()
  })

  it('chama onEdit pelo menu', async () => {
    const onEdit = vi.fn()
    renderRow({ onEdit })

    await openMenu('Ações da recorrência Aluguel')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Editar' }))

    expect(onEdit).toHaveBeenCalled()
  })

  it('pausa uma recorrência ativa pelo menu', async () => {
    renderRow()

    await openMenu('Ações da recorrência Aluguel')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Pausar' }))

    expect(updateMutateAsync).toHaveBeenCalledWith({ id: 1, body: { is_active: false } })
  })

  it('reativa uma recorrência pausada pelo menu', async () => {
    renderRow({ recurrence: recurrence({ is_active: false }) })

    await openMenu('Ações da recorrência Aluguel')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Reativar' }))

    expect(updateMutateAsync).toHaveBeenCalledWith({ id: 1, body: { is_active: true } })
  })

  it('excluir pede confirmação e, ao confirmar, chama a mutação', async () => {
    renderRow()

    await openMenu('Ações da recorrência Aluguel')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Excluir' }))

    expect(await screen.findByText('Excluir Aluguel?')).toBeInTheDocument()
    expect(screen.getByText('Os lançamentos previstos serão excluídos. Os já lançados ficam.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Excluir' }))

    await vi.waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith(1))
  })
})
