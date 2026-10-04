import { render, screen, waitFor } from '@testing-library/react'
import { fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ImportBatch } from '@/api/types'
import { ImportHistoryCard } from './import-history-card'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const refetch = vi.fn()
const revertMutateAsync = vi.fn()

let batchesState: { data: ImportBatch[] | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/imports', () => ({
  useImportBatches: () => ({ ...batchesState, refetch }),
  useRevertImport: () => ({ mutateAsync: revertMutateAsync, isPending: false }),
}))

function batch(overrides: Partial<ImportBatch>): ImportBatch {
  return {
    id: 1,
    account_id: 10,
    account: { id: 10, name: 'Nubank' },
    format: 'nubank',
    format_label: 'Nubank (conta)',
    filename: 'extrato.csv',
    status: 'pending',
    stats: { failed: [] },
    summary: { new: 0, duplicate: 0, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    revertible: false,
    created_at: '2026-10-01T10:00:00+00:00',
    completed_at: null,
    reverted_at: null,
    ...overrides,
  }
}

function renderCard() {
  return render(
    <MemoryRouter>
      <ImportHistoryCard />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  refetch.mockReset()
  revertMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
  batchesState = { data: undefined, isPending: true, isError: false }
})

describe('ImportHistoryCard', () => {
  it('mostra skeletons enquanto carrega', () => {
    const { container } = renderCard()
    expect(container.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(2)
  })

  it('mostra o estado vazio quando não há lotes', () => {
    batchesState = { data: [], isPending: false, isError: false }
    renderCard()
    expect(screen.getByText('Nenhuma importação ainda')).toBeInTheDocument()
  })

  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    batchesState = { data: undefined, isPending: false, isError: true }
    renderCard()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })

  it('lote pendente mostra "Pendente" e o link Continuar', () => {
    batchesState = { data: [batch({ status: 'pending' })], isPending: false, isError: false }
    renderCard()

    expect(screen.getByText('Pendente')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Continuar' })).toHaveAttribute('href', '/importar/1')
  })

  it('lote concluído mostra o resumo das estatísticas', () => {
    batchesState = {
      data: [
        batch({
          status: 'completed',
          stats: { inserted: 3, duplicates: 1, failed: [{ line: 4, reason: 'valor inválido' }] },
          summary: { new: 3, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 1 },
        }),
      ],
      isPending: false,
      isError: false,
    }
    renderCard()

    expect(screen.getByText('Importado')).toBeInTheDocument()
    expect(screen.getByText('3 novas · 1 já importada · 1 inválida')).toBeInTheDocument()
  })

  it('reverte um lote concluído após confirmar no diálogo', async () => {
    batchesState = {
      data: [batch({ id: 7, status: 'completed', revertible: true, stats: { inserted: 1, failed: [] } })],
      isPending: false,
      isError: false,
    }
    renderCard()

    const trigger = screen.getByRole('button', { name: 'Ações da importação de extrato.csv' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Reverter' }))

    fireEvent.click(await screen.findByRole('button', { name: 'Reverter' }))

    await waitFor(() => expect(revertMutateAsync).toHaveBeenCalledWith(7))
    expect(toast.success).toHaveBeenCalledWith('Importação revertida.')
  })

  it('mostra o aviso de limite quando a listagem traz as 50 mais recentes', () => {
    batchesState = {
      data: Array.from({ length: 50 }, (_, index) => batch({ id: index + 1, filename: `extrato-${index}.csv` })),
      isPending: false,
      isError: false,
    }
    renderCard()

    expect(screen.getByText('Mostrando as 50 mais recentes.')).toBeInTheDocument()
  })

  it('não mostra o aviso de limite quando há menos de 50 lotes', () => {
    batchesState = { data: [batch({})], isPending: false, isError: false }
    renderCard()

    expect(screen.queryByText(/mais recentes/)).not.toBeInTheDocument()
  })

  it('lote concluído mas não revertível (não é o mais recente da conta) não mostra menu de ações', () => {
    batchesState = {
      data: [batch({ status: 'completed', revertible: false, stats: { inserted: 1, failed: [] } })],
      isPending: false,
      isError: false,
    }
    renderCard()

    expect(screen.getByText('Importado')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ações da importação/ })).not.toBeInTheDocument()
  })

  it('lote revertido não mostra menu de ações', () => {
    batchesState = { data: [batch({ status: 'reverted', stats: { inserted: 1, failed: [] } })], isPending: false, isError: false }
    renderCard()

    expect(screen.getByText('Revertido')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ações da importação/ })).not.toBeInTheDocument()
  })
})
