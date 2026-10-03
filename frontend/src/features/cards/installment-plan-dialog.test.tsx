import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { InstallmentPlan } from '@/api/types'
import { InstallmentPlanDialog } from './installment-plan-dialog'

const mutateAsync = vi.fn()

vi.mock('@/api/queries/cards', () => ({
  useUpdateInstallmentPlan: () => ({ mutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function plan(overrides: Partial<InstallmentPlan> = {}): InstallmentPlan {
  return {
    id: 1,
    account_id: 1,
    description: 'Notebook',
    total_amount: 120000,
    installments: 10,
    installment_amount: 12000,
    purchase_date: '2025-07-05',
    cancelled_at: null,
    category_id: 5,
    posted_count: 3,
    projected_count: 7,
    remaining_amount: 84000,
    next_date: '2026-04-05',
    ...overrides,
  }
}

function renderDialog(target: InstallmentPlan) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <InstallmentPlanDialog open onOpenChange={() => {}} plan={target} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

describe('InstallmentPlanDialog', () => {
  it('renomear sem tocar a categoria não envia category_id (não sobrescreve as parcelas futuras)', async () => {
    renderDialog(plan())

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Notebook novo' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ id: 1, body: { description: 'Notebook novo' } }))
  })

  it('parcelamento finalizado (sem parcelas futuras): seletor de categoria desabilitado e sem category_id no corpo', async () => {
    renderDialog(plan({ projected_count: 0 }))

    expect(screen.getByRole('combobox')).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ id: 1, body: { description: 'Notebook' } }))
  })

  it('escolher "Sem categoria" envia category_id: null', async () => {
    renderDialog(plan())

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.click(screen.getByText('Sem categoria'))
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ id: 1, body: { description: 'Notebook', category_id: null } }),
    )
  })
})
