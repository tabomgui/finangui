import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BudgetItem } from '@/api/types'
import { BudgetRow } from './budget-row'

const deleteMutateAsync = vi.fn()

vi.mock('@/api/queries/budgets', () => ({
  useDeleteBudget: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function item(overrides: Partial<BudgetItem> = {}): BudgetItem {
  return {
    category: { id: 2, name: 'Moradia', icon: null, color: null },
    amount: 150000,
    source: 'default',
    spent: 50000,
    remaining: 100000,
    percent: 33,
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
})

describe('BudgetRow', () => {
  it('mostra a categoria e o percentual gasto', () => {
    render(<BudgetRow item={item()} month="2026-10" currency="BRL" onEdit={vi.fn()} />)

    expect(screen.getByText('Moradia')).toBeInTheDocument()
    expect(screen.getByText('33%')).toBeInTheDocument()
    expect(screen.queryByText('Só este mês')).not.toBeInTheDocument()
  })

  it('marca "Só este mês" quando a fonte é uma exceção do mês', () => {
    render(<BudgetRow item={item({ source: 'override' })} month="2026-10" currency="BRL" onEdit={vi.fn()} />)

    expect(screen.getByText('Só este mês')).toBeInTheDocument()
  })

  it('chama onEdit ao escolher "Editar"', async () => {
    const onEdit = vi.fn()
    render(<BudgetRow item={item()} month="2026-10" currency="BRL" onEdit={onEdit} />)

    await openMenu(/Ações do orçamento/)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Editar' }))

    expect(onEdit).toHaveBeenCalled()
  })

  it('remover com escopo "Todos os meses" não envia month', async () => {
    render(<BudgetRow item={item()} month="2026-10" currency="BRL" onEdit={vi.fn()} />)

    await openMenu(/Ações do orçamento/)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Remover' }))
    fireEvent.click(screen.getByRole('button', { name: 'Remover' }))

    await waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith({ category_id: 2 }))
  })

  it('remover um item com exceção do mês já sugere o escopo "Só {mês}"', async () => {
    render(<BudgetRow item={item({ source: 'override' })} month="2026-10" currency="BRL" onEdit={vi.fn()} />)

    await openMenu(/Ações do orçamento/)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Remover' }))
    fireEvent.click(screen.getByRole('button', { name: 'Remover' }))

    await waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith({ category_id: 2, month: '2026-10' }))
  })
})
