import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { CardStatement } from '@/api/types'
import { StatementDatesDialog } from './statement-dates-dialog'

const mutateAsync = vi.fn()

vi.mock('@/api/queries/cards', () => ({
  useUpdateStatement: () => ({ mutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const statement: CardStatement = {
  id: 10,
  account_id: 1,
  closing_date: '2026-10-10',
  due_date: '2026-10-17',
  reported_total: 120000,
  total: 120000,
  paid: 0,
  remaining: 120000,
  status: 'open',
  days_until_due: 14,
  is_overdue: false,
  has_divergence: false,
}

function renderDialog(onOpenChange: (open: boolean) => void = () => {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <StatementDatesDialog open statement={statement} onOpenChange={onOpenChange} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('StatementDatesDialog', () => {
  it('abre com as datas e o total informado da fatura', () => {
    renderDialog()

    expect(screen.getByLabelText('Fechamento')).toHaveValue('2026-10-10')
    expect(screen.getByLabelText('Vencimento')).toHaveValue('2026-10-17')
    expect(screen.getByLabelText('Total informado pelo banco')).toHaveValue('1.200,00')
  })

  it('trocar a data de fechamento e salvar chama useUpdateStatement().mutateAsync', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Fechamento'), { target: { value: '2026-10-09' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({
        id: 10,
        body: { closing_date: '2026-10-09', due_date: '2026-10-17', reported_total: 120000 },
      }),
    )
  })

  it('erro 422 em due_date aparece no campo', async () => {
    mutateAsync.mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', null, {
        due_date: ['O vencimento precisa ser no máximo 40 dias após o fechamento.'],
      }),
    )
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(screen.getByText('O vencimento precisa ser no máximo 40 dias após o fechamento.')).toBeInTheDocument(),
    )
  })
})
