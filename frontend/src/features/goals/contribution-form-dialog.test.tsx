import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ContributionFormDialog } from './contribution-form-dialog'

const createMutateAsync = vi.fn()

vi.mock('@/api/queries/goals', () => ({
  useCreateGoalContribution: () => ({ mutateAsync: createMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

beforeEach(() => {
  createMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

function renderDialog() {
  return render(<ContributionFormDialog open onOpenChange={vi.fn()} goalId={7} />)
}

describe('ContributionFormDialog', () => {
  it('exige um valor maior que zero', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(screen.getByText('Informe um valor maior que zero.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('registra um aporte com valor positivo', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '200,00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ amount: 20000 })),
    )
    expect(toast.success).toHaveBeenCalledWith('Aporte registrado.')
  })

  it('com "Retirada" ativado, envia o valor negativo', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('switch', { name: 'Retirada' }))
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '150,00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ amount: -15000 })),
    )
    expect(toast.success).toHaveBeenCalledWith('Retirada registrada.')
  })

  it('inclui a nota quando preenchida', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '100,00' } })
    fireEvent.change(screen.getByLabelText('Nota (opcional)'), { target: { value: 'Décimo terceiro' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ note: 'Décimo terceiro' })),
    )
  })
})
