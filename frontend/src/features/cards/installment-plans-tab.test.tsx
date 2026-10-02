import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { InstallmentPlan } from '@/api/types'
import { InstallmentPlansTab } from './installment-plans-tab'

let mockPlans: InstallmentPlan[] = []
const cancelMutateAsync = vi.fn()

vi.mock('@/api/queries/cards', () => ({
  useInstallmentPlans: () => ({ data: mockPlans, isPending: false, isError: false, refetch: vi.fn() }),
  useCancelInstallmentPlan: () => ({ mutateAsync: cancelMutateAsync, isPending: false }),
  useUpdateInstallmentPlan: () => ({ mutateAsync: vi.fn(), isPending: false }),
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
    category_id: null,
    posted_count: 3,
    projected_count: 7,
    remaining_amount: 84000,
    next_date: '2026-04-05',
    ...overrides,
  }
}

function renderTab() {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <InstallmentPlansTab cardId={1} currency="BRL" />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockPlans = []
  cancelMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('InstallmentPlansTab', () => {
  it('mostra descrição, parcelas, progresso, restante e próxima data', () => {
    mockPlans = [plan()]

    renderTab()

    expect(screen.getByText('Notebook')).toBeInTheDocument()
    expect(screen.getByText('10x de R$ 120,00')).toBeInTheDocument()
    expect(screen.getByText('3 de 10 lançadas')).toBeInTheDocument()
    expect(screen.getByText('Faltam R$ 840,00')).toBeInTheDocument()
    expect(screen.getByText('Próxima em 05/04/2026')).toBeInTheDocument()
  })

  it('plano cancelado mostra badge "Cancelado" e não oferece "Cancelar parcelamento"', () => {
    mockPlans = [plan({ cancelled_at: '2026-01-01T00:00:00Z', projected_count: 0, remaining_amount: 0, next_date: null })]

    renderTab()

    expect(screen.getByText('Cancelado')).toBeInTheDocument()

    fireEvent.pointerDown(screen.getByRole('button', { name: 'Ações do parcelamento Notebook' }), { button: 0 })

    expect(screen.queryByText('Cancelar parcelamento')).not.toBeInTheDocument()
  })

  it('"Cancelar parcelamento" abre confirmação e confirma chamando useCancelInstallmentPlan().mutateAsync', async () => {
    mockPlans = [plan()]

    renderTab()

    fireEvent.pointerDown(screen.getByRole('button', { name: 'Ações do parcelamento Notebook' }), { button: 0 })
    fireEvent.click(screen.getByText('Cancelar parcelamento'))

    expect(screen.getByText('As parcelas futuras serão excluídas. As já lançadas ficam.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar parcelamento' }))

    await waitFor(() => expect(cancelMutateAsync).toHaveBeenCalledWith(1))
    expect(toast.success).toHaveBeenCalledWith('Parcelamento cancelado.')
  })

  it('lista vazia mostra "Nenhum parcelamento neste cartão."', () => {
    mockPlans = []

    renderTab()

    expect(screen.getByText('Nenhum parcelamento neste cartão.')).toBeInTheDocument()
  })
})
