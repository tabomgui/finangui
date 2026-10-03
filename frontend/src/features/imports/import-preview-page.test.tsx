import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { ImportPreview } from '@/api/types'
import { ImportPreviewPage } from './import-preview-page'

type PreviewRow = ImportPreview['rows'][number]

let previewState: { data: ImportPreview | undefined; isError: boolean; error: unknown; isPending: boolean }
let confirmPending = false
let cancelPending = false
const refetch = vi.fn()
const confirmMutateAsync = vi.fn()
const cancelMutateAsync = vi.fn()

vi.mock('@/api/queries/imports', () => ({
  useImportBatch: () => ({ ...previewState, refetch }),
  useConfirmImport: () => ({ mutateAsync: confirmMutateAsync, isPending: confirmPending }),
  useCancelImport: () => ({ mutateAsync: cancelMutateAsync, isPending: cancelPending }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({ data: [{ id: 5, name: 'Mercado' }] }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

// A linha do checkbox inclui data e valor (ver import-preview-row.tsx), que os testes não
// precisam fixar byte a byte — basta conferir que o rótulo começa com "Selecionar <descrição>".
function checkboxFor(description: string) {
  return screen.getByRole('checkbox', { name: new RegExp(`^Selecionar ${description} em`) })
}

function previewRow(overrides: Partial<PreviewRow>): PreviewRow {
  return {
    line: 1,
    date: '2026-10-01',
    amount: 1000,
    direction: 'out',
    description: 'Padaria',
    outcome: 'new',
    ...overrides,
  }
}

function preview(overrides: Partial<ImportPreview> = {}): ImportPreview {
  return {
    batch: {
      id: 1,
      account_id: 9,
      account: { id: 9, name: 'Nubank' },
      format: 'nubank',
      format_label: 'Nubank (conta)',
      filename: 'extrato.csv',
      status: 'pending',
      stats: { failed: [] },
      created_at: '2026-10-01T10:00:00+00:00',
      completed_at: null,
      reverted_at: null,
    },
    rows: [previewRow({})],
    summary: { new: 1, duplicate: 0, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    ...overrides,
  }
}

function renderPage(id = 1) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/importar/${id}`]}>
        <Routes>
          <Route path="/importar" element={<div>Lista de importações</div>} />
          <Route path="/importar/:id" element={<ImportPreviewPage />} />
          <Route path="/transacoes" element={<div>Lista de transações</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  refetch.mockReset()
  confirmMutateAsync.mockReset()
  cancelMutateAsync.mockReset()
  confirmPending = false
  cancelPending = false
  vi.mocked(toast.success).mockReset()
  vi.mocked(toast.error).mockReset()
  previewState = { data: preview(), isError: false, error: null, isPending: false }
})

describe('ImportPreviewPage', () => {
  it('mostra o resumo e o desfecho de cada linha', () => {
    previewState.data = preview({
      rows: [previewRow({ line: 1, outcome: 'new' }), previewRow({ line: 2, outcome: 'duplicate', description: 'Já visto' })],
      summary: { new: 1, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    })
    renderPage()

    expect(screen.getByText('1 nova · 1 já importada')).toBeInTheDocument()
    expect(screen.getByText('Nova')).toBeInTheDocument()
    expect(screen.getByText('Já importada')).toBeInTheDocument()
  })

  it('desmarcar uma linha envia o skip_lines certo (nunca a duplicada, que já não está marcada)', async () => {
    previewState.data = preview({
      rows: [
        previewRow({ line: 1, description: 'Padaria' }),
        previewRow({ line: 2, description: 'Mercado' }),
        previewRow({ line: 3, outcome: 'duplicate', description: 'Repetida' }),
      ],
      summary: { new: 2, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    })
    confirmMutateAsync.mockResolvedValue({})
    renderPage()

    fireEvent.click(checkboxFor('Mercado'))
    fireEvent.click(screen.getByRole('button', { name: 'Importar 1 lançamento' }))

    // `skip_lines` só com a linha 2 (desmarcada e importável) — nunca a 3, que é duplicada e
    // nunca esteve marcada (ver I2: skip_lines não inclui duplicatas).
    await waitFor(() => expect(confirmMutateAsync).toHaveBeenCalledWith({ id: 1, body: { skip_lines: [2] } }))
  })

  it('linha duplicada aparece desmarcada e desabilitada, e não entra no total selecionado', () => {
    previewState.data = preview({
      rows: [previewRow({ line: 1, outcome: 'new', description: 'Nova' }), previewRow({ line: 2, outcome: 'duplicate', description: 'Repetida' })],
      summary: { new: 1, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    })
    renderPage()

    const duplicateCheckbox = checkboxFor('Repetida')
    expect(duplicateCheckbox).not.toBeChecked()
    expect(duplicateCheckbox).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Importar 1 lançamento' })).toBeInTheDocument()
  })

  it('"Marcar todas" seleciona de novo as linhas importáveis, sem afetar a duplicada', () => {
    previewState.data = preview({
      rows: [previewRow({ line: 1, outcome: 'new', description: 'Padaria' }), previewRow({ line: 2, outcome: 'duplicate', description: 'Repetida' })],
      summary: { new: 1, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    })
    renderPage()

    fireEvent.click(checkboxFor('Padaria'))
    expect(screen.getByRole('button', { name: 'Importar 0 lançamentos' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Marcar todas' }))
    expect(screen.getByRole('button', { name: 'Importar 1 lançamento' })).toBeInTheDocument()
    expect(checkboxFor('Repetida')).not.toBeChecked()
  })

  it('desmarcar a única linha desabilita o botão de confirmar (0 selecionadas)', () => {
    renderPage()

    fireEvent.click(checkboxFor('Padaria'))

    const confirmButton = screen.getByRole('button', { name: 'Importar 0 lançamentos' })
    expect(confirmButton).toBeDisabled()
  })

  it('desabilita confirmar e cancelar enquanto a confirmação está em andamento', () => {
    confirmPending = true
    renderPage()

    expect(screen.getByRole('button', { name: 'Importar 1 lançamento' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled()
  })

  it('desabilita confirmar e cancelar enquanto o cancelamento está em andamento', () => {
    cancelPending = true
    renderPage()

    expect(screen.getByRole('button', { name: 'Importar 1 lançamento' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled()
  })

  it('confirmar navega para /importar e mostra toast de sucesso', async () => {
    confirmMutateAsync.mockResolvedValue({})
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Importar 1 lançamento' }))

    await waitFor(() => expect(screen.getByText('Lista de importações')).toBeInTheDocument())
    expect(toast.success).toHaveBeenCalledWith('Importação concluída.')
  })

  it('409 ao confirmar mostra toast de erro e recarrega a prévia', async () => {
    confirmMutateAsync.mockRejectedValue(new ApiError(409, 'Esse lote já foi confirmado.', 'import_batch_already_confirmed'))
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Importar 1 lançamento' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Esse lote já foi confirmado.'))
    expect(refetch).toHaveBeenCalled()
  })

  it('cancelar chama useCancelImport e volta para /importar', async () => {
    cancelMutateAsync.mockResolvedValue(undefined)
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }))

    await waitFor(() => expect(cancelMutateAsync).toHaveBeenCalledWith(1))
    expect(screen.getByText('Lista de importações')).toBeInTheDocument()
  })

  it('409 ao cancelar (lote já confirmado por outra aba) mostra toast de erro e recarrega', async () => {
    cancelMutateAsync.mockRejectedValue(new ApiError(409, 'Esse lote já foi confirmado.', 'import_batch_already_confirmed'))
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Esse lote já foi confirmado.'))
    expect(refetch).toHaveBeenCalled()
  })

  it('lote concluído mostra o resumo final, o status e o link para ver lançamentos', () => {
    previewState.data = {
      batch: {
        id: 1,
        account_id: 9,
        account: { id: 9, name: 'Nubank' },
        format: 'nubank',
        format_label: 'Nubank (conta)',
        filename: 'extrato.csv',
        status: 'completed',
        stats: { inserted: 2, duplicates: 1, failed: [] },
        created_at: '2026-10-01T10:00:00+00:00',
        completed_at: '2026-10-01T10:05:00+00:00',
        reverted_at: null,
      },
      rows: [],
      summary: { new: 2, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    }
    renderPage()

    expect(screen.getByText('Importado')).toBeInTheDocument()
    expect(screen.getByText('2 novas · 1 já importada')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver lançamentos' })).toHaveAttribute('href', '/transacoes?conta=9')
  })

  it('lote revertido mostra o status e esconde o link para ver lançamentos', () => {
    previewState.data = {
      batch: {
        id: 1,
        account_id: 9,
        account: { id: 9, name: 'Nubank' },
        format: 'nubank',
        format_label: 'Nubank (conta)',
        filename: 'extrato.csv',
        status: 'reverted',
        stats: { inserted: 2, duplicates: 1, failed: [] },
        created_at: '2026-10-01T10:00:00+00:00',
        completed_at: '2026-10-01T10:05:00+00:00',
        reverted_at: '2026-10-01T11:00:00+00:00',
      },
      rows: [],
      summary: { new: 2, duplicate: 1, update: 0, replace_installment: 0, adopt: 0, swap_pending: 0, failed: 0 },
    }
    renderPage()

    expect(screen.getByText('Revertido')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Ver lançamentos' })).not.toBeInTheDocument()
  })

  it('limita "Linhas ignoradas" a 50 e mostra quantas ficaram de fora', () => {
    const base = preview()
    previewState.data = preview({
      batch: { ...base.batch, stats: { failed: Array.from({ length: 62 }, (_, index) => ({ line: index + 1, reason: 'valor inválido' })) } },
    })
    renderPage()

    expect(screen.getAllByText(/^Linha \d+: valor inválido$/)).toHaveLength(50)
    expect(screen.getByText('e mais 12 linhas.')).toBeInTheDocument()
  })

  it('lote não encontrado (404) volta para /importar com toast', async () => {
    previewState = { data: undefined, isError: true, error: new ApiError(404, 'Não encontrado.'), isPending: false }
    renderPage()

    await waitFor(() => expect(screen.getByText('Lista de importações')).toBeInTheDocument())
    expect(toast.error).toHaveBeenCalledWith('Importação não encontrada.')
  })

  it('erro diferente de 404 mostra tela de erro com opção de tentar de novo', () => {
    previewState = { data: undefined, isError: true, error: new ApiError(500, 'Erro inesperado no servidor. Tente novamente.'), isPending: false }
    renderPage()

    expect(screen.queryByText('Lista de importações')).not.toBeInTheDocument()
    expect(screen.getByText('Não foi possível carregar a importação.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })
})
