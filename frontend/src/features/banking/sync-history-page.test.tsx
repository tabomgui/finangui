import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { BankConnection, BankSyncRun } from '@/api/types'
import { SyncHistoryPage } from './sync-history-page'

const useBankConnections = vi.fn()
const useSyncConnection = vi.fn()
const useBankSyncRuns = vi.fn()
const useBankSyncRun = vi.fn()
const syncMutateAsync = vi.fn()
const fetchNextPage = vi.fn()
const refetch = vi.fn()

vi.mock('@/api/queries/bank-connections', () => ({
  useBankConnections: (...args: unknown[]) => useBankConnections(...args),
  useSyncConnection: (...args: unknown[]) => useSyncConnection(...args),
}))

vi.mock('@/api/queries/bank-sync-runs', () => ({
  useBankSyncRuns: (...args: unknown[]) => useBankSyncRuns(...args),
  useBankSyncRun: (...args: unknown[]) => useBankSyncRun(...args),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function connection(overrides: Partial<BankConnection> = {}): BankConnection {
  return {
    id: 9,
    provider: 'pluggy',
    status: 'active',
    institution_name: 'Banco Fictício',
    institution_logo_url: null,
    last_synced_at: null,
    last_error: null,
    accounts: [],
    pending_accounts: [],
    unlinked_accounts: [],
    ...overrides,
  }
}

function run(overrides: Partial<BankSyncRun> = {}): BankSyncRun {
  return {
    id: 1,
    connection_id: 9,
    trigger: 'scheduled',
    status: 'success',
    started_at: '2026-10-05T14:30:00Z',
    finished_at: '2026-10-05T14:30:42Z',
    refresh_requested: true,
    added_count: 3,
    updated_count: 0,
    bills_count: 0,
    warnings: [],
    ...overrides,
  }
}

type RunsResult = ReturnType<typeof defaultRunsResult> & { error: unknown }

function setRuns(pages: BankSyncRun[][], extra: Partial<RunsResult> = {}) {
  useBankSyncRuns.mockReturnValue({ ...defaultRunsResult(pages), ...extra })
}

function defaultRunsResult(pages: BankSyncRun[][]) {
  return {
    data: { pages: pages.map((data) => ({ data, meta: { next_cursor: null } })) },
    isPending: false,
    isError: false,
    error: null as unknown,
    refetch,
    hasNextPage: false,
    isFetchingNextPage: false,
    fetchNextPage,
  }
}

function renderPage() {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/conexoes/9/sincronizacoes']}>
        <Routes>
          <Route path="/conexoes/:id/sincronizacoes" element={<SyncHistoryPage />} />
          <Route path="/contas" element={<div>Página de contas</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  useBankConnections.mockReturnValue({ data: [connection()] })
  useSyncConnection.mockReturnValue({ mutateAsync: syncMutateAsync, isPending: false })
  useBankSyncRun.mockReturnValue({ data: undefined, isPending: false, isError: false, refetch: vi.fn() })
  syncMutateAsync.mockReset().mockResolvedValue(undefined)
  fetchNextPage.mockReset()
  refetch.mockReset()
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('SyncHistoryPage', () => {
  it('mostra o nome do banco e as runs do histórico', () => {
    setRuns([[run({ id: 1, added_count: 4, updated_count: 2 }), run({ id: 2, trigger: 'manual' })]])
    renderPage()

    expect(screen.getByText('Banco Fictício')).toBeInTheDocument()
    expect(screen.getByText('4 adicionados · 2 atualizados')).toBeInTheDocument()
    expect(screen.getByText('Manual')).toBeInTheDocument()
  })

  it('mostra skeletons enquanto carrega', () => {
    setRuns([[]], { isPending: true, data: undefined })
    const { container } = renderPage()

    expect(container.querySelectorAll('[data-slot="skeleton"]').length).toBeGreaterThan(0)
  })

  it('mostra estado vazio sem sincronizações', () => {
    setRuns([[]])
    renderPage()

    expect(screen.getByText('Nenhuma sincronização ainda')).toBeInTheDocument()
  })

  it('mostra erro com retry quando a busca falha', () => {
    setRuns([[]], { isError: true, data: undefined })
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })

  it('"Carregar mais" busca a próxima página', () => {
    setRuns([[run()]], { hasNextPage: true })
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Carregar mais' }))
    expect(fetchNextPage).toHaveBeenCalled()
  })

  it('aciona a sincronização manual pelo botão do cabeçalho', async () => {
    setRuns([[run()]])
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Sincronizar agora' }))

    await vi.waitFor(() => expect(syncMutateAsync).toHaveBeenCalledWith(9))
    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('Sincronização iniciada.'))
  })

  it('desabilita "Sincronizar agora" enquanto há uma run em andamento', () => {
    setRuns([[run({ status: 'running', finished_at: undefined })]])
    renderPage()

    expect(screen.getByRole('button', { name: 'Sincronizar agora' })).toBeDisabled()
  })

  it('mostra aviso específico quando já há sincronização em andamento (409)', async () => {
    syncMutateAsync.mockRejectedValue(new ApiError(409, 'Esta conexão já está sincronizando.', 'connection_sync_in_progress'))
    setRuns([[run()]])
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Sincronizar agora' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Sincronização em andamento.'))
  })

  it('mostra a mensagem padrão para outros erros do disparo manual (ex.: 429)', async () => {
    syncMutateAsync.mockRejectedValue(new ApiError(429, 'Muitas tentativas. Aguarde um minuto e tente de novo.'))
    setRuns([[run()]])
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Sincronizar agora' }))

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Muitas tentativas. Aguarde um minuto e tente de novo.'))
  })

  it('redireciona para /contas quando a conexão não existe (404)', () => {
    setRuns([[]], { isError: true, error: new ApiError(404, 'Não encontrado.'), data: undefined })
    renderPage()

    expect(screen.getByText('Página de contas')).toBeInTheDocument()
  })

  it('mostra a nota de conexão não atualizável quando refresh_unsupported é true', () => {
    useBankConnections.mockReturnValue({ data: [connection({ refresh_unsupported: true })] })
    setRuns([[run()]])
    renderPage()

    expect(screen.getByText(/atualizada pela Pluggy uma vez por dia/)).toBeInTheDocument()
  })

  it('sem refresh_unsupported, a nota não aparece', () => {
    setRuns([[run()]])
    renderPage()

    expect(screen.queryByText(/atualizada pela Pluggy uma vez por dia/)).not.toBeInTheDocument()
  })

  it('abre o detalhe da run ao clicar na linha', () => {
    setRuns([[run({ id: 42 })]])
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: /Sucesso/ }))

    expect(useBankSyncRun).toHaveBeenCalledWith(42)
  })
})
