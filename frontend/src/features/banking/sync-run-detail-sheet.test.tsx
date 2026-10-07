import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BankSyncRunDetail } from '@/api/types'
import { SyncRunDetailSheet } from './sync-run-detail-sheet'

const useBankSyncRun = vi.fn()

vi.mock('@/api/queries/bank-sync-runs', () => ({
  useBankSyncRun: (...args: unknown[]) => useBankSyncRun(...args),
}))

function detail(overrides: Partial<BankSyncRunDetail> = {}): BankSyncRunDetail {
  return {
    id: 1,
    connection_id: 10,
    trigger: 'manual',
    status: 'success',
    started_at: '2026-10-05T14:30:00Z',
    finished_at: '2026-10-05T14:30:42Z',
    refresh_requested: true,
    added_count: 1,
    updated_count: 0,
    bills_count: 0,
    warnings: [],
    items: [{ id: 1, account_name: 'Nubank', date: '2026-10-05', description: 'Supermercado', amount: 5000, direction: 'out' }],
    items_truncated: false,
    ...overrides,
  }
}

beforeEach(() => {
  useBankSyncRun.mockReset()
})

describe('SyncRunDetailSheet', () => {
  it('fica fechado quando runId é null', () => {
    useBankSyncRun.mockReturnValue({ data: undefined, isPending: false, isError: false, refetch: vi.fn() })
    render(<SyncRunDetailSheet runId={null} onOpenChange={vi.fn()} />)

    expect(screen.queryByText('Lançamentos adicionados')).not.toBeInTheDocument()
  })

  it('mostra os lançamentos adicionados com conta, data, descrição e valor', () => {
    useBankSyncRun.mockReturnValue({ data: detail(), isPending: false, isError: false, refetch: vi.fn() })
    render(<SyncRunDetailSheet runId={1} onOpenChange={vi.fn()} />)

    expect(screen.getByText('Supermercado')).toBeInTheDocument()
    expect(screen.getByText(/Nubank/)).toBeInTheDocument()
    expect(screen.getByText(/50,00/)).toBeInTheDocument()
  })

  it('mostra a nota de lista cortada quando items_truncated', () => {
    useBankSyncRun.mockReturnValue({
      data: detail({ added_count: 600, items_truncated: true }),
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    })
    render(<SyncRunDetailSheet runId={1} onOpenChange={vi.fn()} />)

    expect(screen.getByText(/Mostrando só os primeiros 1 de 600/)).toBeInTheDocument()
  })

  it('mostra o estado vazio sem lançamentos adicionados', () => {
    useBankSyncRun.mockReturnValue({ data: detail({ items: [], added_count: 0 }), isPending: false, isError: false, refetch: vi.fn() })
    render(<SyncRunDetailSheet runId={1} onOpenChange={vi.fn()} />)

    expect(screen.getByText('Nenhum lançamento adicionado nesta sincronização.')).toBeInTheDocument()
  })

  it('mostra erro com retry quando a busca falha', () => {
    const refetch = vi.fn()
    useBankSyncRun.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch })
    render(<SyncRunDetailSheet runId={1} onOpenChange={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })
})
