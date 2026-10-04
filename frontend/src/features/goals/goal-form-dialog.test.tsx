import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Goal } from '@/api/types'
import { GoalFormDialog } from './goal-form-dialog'

const createMutateAsync = vi.fn()
const updateMutateAsync = vi.fn()

vi.mock('@/api/queries/goals', () => ({
  useCreateGoal: () => ({ mutateAsync: createMutateAsync, isPending: false }),
  useUpdateGoal: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({
    data: [{ id: 9, name: 'Poupança', type: 'savings', color: null, icon: null, is_archived: false }],
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function goal(overrides: Partial<Goal> = {}): Goal {
  return {
    id: 1,
    name: 'Viagem',
    target_amount: 500000,
    target_date: null,
    account_id: null,
    color: '#10b981',
    icon: 'piggy-bank',
    achieved_at: null,
    progress: 0,
    remaining: 500000,
    percent: 0,
    ...overrides,
  }
}

async function selectAccount() {
  const trigger = screen.getByLabelText('Conta')
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: 'Poupança' }))
}

beforeEach(() => {
  createMutateAsync.mockReset().mockResolvedValue(undefined)
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

function renderDialog(props: Partial<Parameters<typeof GoalFormDialog>[0]> = {}) {
  return render(<GoalFormDialog open onOpenChange={vi.fn()} {...props} />)
}

describe('GoalFormDialog', () => {
  it('exige o nome', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() => expect(screen.getByText('Informe o nome da meta.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('exige um valor maior que zero', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Viagem' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() => expect(screen.getByText('Informe um valor maior que zero.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('cria a meta sem data e sem conta', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Viagem' } })
    fireEvent.change(screen.getByLabelText('Valor da meta'), { target: { value: '5.000,00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({ name: 'Viagem', target_amount: 500000, target_date: null, account_id: null }),
      ),
    )
    expect(toast.success).toHaveBeenCalledWith('Meta criada.')
  })

  it('ativando a data-limite exige uma data antes de enviar', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Viagem' } })
    fireEvent.change(screen.getByLabelText('Valor da meta'), { target: { value: '5.000,00' } })
    fireEvent.click(screen.getByRole('switch', { name: 'Definir uma data-limite' }))
    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() => expect(screen.getByText('Informe a data.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('envia a data quando preenchida', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Viagem' } })
    fireEvent.change(screen.getByLabelText('Valor da meta'), { target: { value: '5.000,00' } })
    fireEvent.click(screen.getByRole('switch', { name: 'Definir uma data-limite' }))
    fireEvent.change(screen.getByLabelText('Data'), { target: { value: '2026-12-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ target_date: '2026-12-01' })),
    )
  })

  it('ativando "Acompanhar saldo de uma conta" exige a conta e a envia', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Viagem' } })
    fireEvent.change(screen.getByLabelText('Valor da meta'), { target: { value: '5.000,00' } })
    fireEvent.click(screen.getByRole('switch', { name: 'Acompanhar saldo de uma conta' }))
    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() => expect(screen.getByText('Escolha a conta.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()

    await selectAccount()
    fireEvent.click(screen.getByRole('button', { name: 'Criar meta' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ account_id: 9 })))
  })

  it('editando: preenche os campos e já liga "Acompanhar saldo de uma conta" quando a meta tem conta', () => {
    renderDialog({ goal: goal({ account_id: 9 }) })

    expect(screen.getByLabelText('Nome')).toHaveValue('Viagem')
    expect(screen.getByLabelText('Valor da meta')).toHaveValue('5.000,00')
    expect(screen.getByRole('switch', { name: 'Acompanhar saldo de uma conta' })).toHaveAttribute('data-state', 'checked')
  })

  it('editando, envia pelo PATCH', async () => {
    renderDialog({ goal: goal() })

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(updateMutateAsync).toHaveBeenCalledWith({ id: 1, body: expect.objectContaining({ name: 'Viagem' }) }),
    )
  })
})
