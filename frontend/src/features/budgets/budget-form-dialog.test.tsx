import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BudgetItem } from '@/api/types'
import { BudgetFormDialog } from './budget-form-dialog'

const saveMutateAsync = vi.fn()

vi.mock('@/api/queries/budgets', () => ({
  useSaveBudget: () => ({ mutateAsync: saveMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({
    data: [{ id: 2, parent_id: null, name: 'Moradia', kind: 'expense', icon: null, color: null, is_archived: false }],
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

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

beforeEach(() => {
  saveMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

function renderDialog(props: Partial<Parameters<typeof BudgetFormDialog>[0]> = {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <BudgetFormDialog open onOpenChange={vi.fn()} month="2026-10" {...props} />
    </QueryClientProvider>,
  )
}

describe('BudgetFormDialog', () => {
  it('exige a categoria ao orçar uma categoria nova', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(screen.getByText('Escolha a categoria.')).toBeInTheDocument())
    expect(saveMutateAsync).not.toHaveBeenCalled()
  })

  it('cria o padrão mensal (sem mês no corpo) quando o escopo é "Todos os meses"', async () => {
    renderDialog()

    fireEvent.click(screen.getByLabelText('Categoria'))
    fireEvent.click(await screen.findByText('Moradia'))
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '500,00' } })

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(saveMutateAsync).toHaveBeenCalledWith({ category_id: 2, amount: 50000 }))
    expect(toast.success).toHaveBeenCalledWith('Categoria orçada.')
  })

  it('escopo "Só {mês}" inclui month no corpo', async () => {
    renderDialog()

    fireEvent.click(screen.getByLabelText('Categoria'))
    fireEvent.click(await screen.findByText('Moradia'))
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '500,00' } })
    fireEvent.click(screen.getByRole('radio', { name: /Só/ }))

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(saveMutateAsync).toHaveBeenCalledWith({ category_id: 2, amount: 50000, month: '2026-10' }))
  })

  it('"Sem orçamento só neste mês" esconde o valor e envia amount 0 com o mês', async () => {
    renderDialog()

    fireEvent.click(screen.getByLabelText('Categoria'))
    fireEvent.click(await screen.findByText('Moradia'))
    fireEvent.click(screen.getByRole('radio', { name: /Só/ }))
    fireEvent.click(screen.getByRole('switch'))

    expect(screen.queryByLabelText('Valor')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(saveMutateAsync).toHaveBeenCalledWith({ category_id: 2, amount: 0, month: '2026-10' }))
  })

  it('editando: mostra a categoria fixa (sem seletor) e preenche o valor', () => {
    renderDialog({ item: item() })

    expect(screen.queryByLabelText('Categoria')).not.toBeInTheDocument()
    expect(screen.getByText('Moradia')).toBeInTheDocument()
    expect(screen.getByLabelText('Valor')).toHaveValue('1.500,00')
  })

  it('editando um item com exceção do mês, o escopo inicial já é "Só {mês}"', () => {
    renderDialog({ item: item({ source: 'override' }) })

    expect(screen.getByRole('radio', { name: /Só/ })).toHaveAttribute('data-state', 'on')
  })
})
