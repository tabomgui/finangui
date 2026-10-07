import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DashboardSummary } from '@/api/types'
import { DashboardPage } from './dashboard-page'

// Fixa "hoje" para o teste não depender do relógio real: `?mes=2026-10` é tratado como o mês
// corrente e `?mes=2026-09` como mês passado em todos os casos abaixo.
vi.mock('@/lib/date', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/date')>()
  return { ...actual, appToday: () => '2026-10-06' }
})

const useDashboard = vi.fn()

vi.mock('@/api/queries/dashboard', () => ({
  useDashboard: (...args: unknown[]) => useDashboard(...args),
}))

vi.mock('@/api/queries/bank-connections', () => ({ useBankConnections: () => ({ data: [] }) }))
vi.mock('@/api/queries/auth', () => ({ useMe: () => ({ data: { banking_enabled: true } }) }))
vi.mock('../banking/use-reconnect-flow', () => ({
  useReconnectFlow: () => ({ reconnect: vi.fn(), isPending: false, widget: null }),
}))
vi.mock('@/api/queries/transfer-suggestions', () => ({ useTransferSuggestions: () => ({ data: undefined }) }))
vi.mock('@/api/queries/recurrences', () => ({
  useOverdueOccurrences: () => ({ data: undefined, isPending: false }),
  useSkipOccurrence: () => ({ isPending: false, variables: undefined, mutateAsync: vi.fn() }),
  useConfirmOccurrence: () => ({ isPending: false, mutateAsync: vi.fn() }),
}))
vi.mock('@/api/queries/cards', () => ({ useCards: () => ({ data: [] }) }))
vi.mock('@/api/queries/transactions', () => ({ useRecentTransactions: () => ({ data: [], isPending: false, isError: false, refetch: vi.fn() }) }))
// O card de distribuição de gastos (ver spending-card.test.tsx) não aparece em nenhum destes
// testes (todos ficam com `accounts: []`, que cai no estado vazio da página antes dele), mas
// mockamos por precaução: sem isso, um teste futuro com contas chamaria a query de verdade.
vi.mock('@/api/queries/reports', () => ({ useSpendingBreakdown: () => ({ data: undefined, isPending: true, isError: false, isPlaceholderData: false, refetch: vi.fn() }) }))

// Mocka o calendário lazy-loaded (testado de verdade em `balance-day-picker.test.tsx`): aqui só
// interessa a integração entre a escolha de dia/"Voltar para hoje" e a URL/query da página.
vi.mock('./balance-day-picker', () => ({
  BalanceDayPicker: ({
    onSelect,
    onBackToToday,
  }: {
    selected: string
    onSelect: (day: string) => void
    onBackToToday: () => void
  }) => (
    <div data-testid="day-picker">
      <button type="button" onClick={() => onSelect('2026-10-02')}>
        escolher-02-out
      </button>
      <button type="button" onClick={() => onSelect('2026-09-30')}>
        escolher-30-set
      </button>
      <button type="button" onClick={() => onSelect('2026-10-06')}>
        escolher-hoje
      </button>
      <button type="button" onClick={onBackToToday}>
        Voltar para hoje
      </button>
    </div>
  ),
}))

function dashboardData(overrides: Partial<DashboardSummary> = {}): DashboardSummary {
  return {
    month: '2026-10',
    currency: 'BRL',
    total_balance: 100000,
    balance_date: '2026-10-06',
    today: '2026-10-06',
    accounts: [],
    income: 0,
    expense: 0,
    net: 0,
    ...overrides,
  }
}

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.pathname + location.search}</div>
}

function renderPage(initialEntry = '/') {
  const client = new QueryClient()
  client.setQueryData(['notifications', 'unread-count'], 0)

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <LocationProbe />
        <DashboardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function mockDashboard(data: DashboardSummary) {
  useDashboard.mockReturnValue({ data, isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })
}

beforeEach(() => {
  useDashboard.mockClear()
})

describe('DashboardPage: seletor de dia do saldo', () => {
  it('sem ?dia na URL, não manda date (o backend já usa hoje como padrão)', () => {
    mockDashboard(dashboardData())

    renderPage('/?mes=2026-10')

    expect(useDashboard).toHaveBeenCalledWith('2026-10', undefined)
  })

  it('sem ?dia na URL, não manda date mesmo num mês passado (mês e dia são independentes)', () => {
    mockDashboard(dashboardData({ balance_date: '2026-10-06' }))

    renderPage('/?mes=2026-09')

    expect(useDashboard).toHaveBeenCalledWith('2026-09', undefined)
  })

  it('com ?dia na URL, passa o dia pro hook', () => {
    mockDashboard(dashboardData({ balance_date: '2026-09-15' }))

    renderPage('/?mes=2026-10&dia=2026-09-15')

    expect(useDashboard).toHaveBeenCalledWith('2026-10', '2026-09-15')
  })

  it.each(['2099-01-01', '2026-02-30'])('?dia=%s é inválido: ignora (não manda date) e tira da URL', async (invalidDay) => {
    mockDashboard(dashboardData())

    renderPage(`/?mes=2026-10&dia=${invalidDay}`)

    expect(useDashboard).toHaveBeenCalledWith('2026-10', undefined)
    await waitFor(() => expect(screen.getByTestId('location')).not.toHaveTextContent('dia='))
  })

  it('escolher um dia no calendário grava ?dia na URL e refaz a query com o novo dia', async () => {
    mockDashboard(dashboardData())

    renderPage('/?mes=2026-10')

    fireEvent.click(screen.getByText('Saldo em 06/10/2026'))
    await screen.findByTestId('day-picker')
    fireEvent.click(screen.getByText('escolher-02-out'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-10&dia=2026-10-02')
    expect(useDashboard).toHaveBeenLastCalledWith('2026-10', '2026-10-02')
  })

  it('escolher hoje no calendário (não o atalho "Voltar para hoje") também tira ?dia da URL', async () => {
    mockDashboard(dashboardData({ balance_date: '2026-09-15' }))

    renderPage('/?mes=2026-10&dia=2026-09-15')

    fireEvent.click(screen.getByText('Saldo em 15/09/2026'))
    await screen.findByTestId('day-picker')
    fireEvent.click(screen.getByText('escolher-hoje'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-10')
    expect(screen.getByTestId('location')).not.toHaveTextContent('dia=')
  })

  it('trocar o mês pela seta mantém o ?dia escolhido na URL', () => {
    mockDashboard(dashboardData({ balance_date: '2026-09-15' }))

    renderPage('/?mes=2026-10&dia=2026-09-15')

    fireEvent.click(screen.getByLabelText('Próximo mês'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-11&dia=2026-09-15')
    expect(useDashboard).toHaveBeenLastCalledWith('2026-11', '2026-09-15')
  })

  it('escolher 30/09 e depois trocar de mês mantém 30/09 (dia e mês são independentes)', async () => {
    mockDashboard(dashboardData({ balance_date: '2026-10-06' }))

    renderPage('/?mes=2026-09')

    fireEvent.click(screen.getByText('Saldo em 06/10/2026'))
    await screen.findByTestId('day-picker')
    fireEvent.click(screen.getByText('escolher-30-set'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-09&dia=2026-09-30')

    fireEvent.click(screen.getByLabelText('Próximo mês'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-10&dia=2026-09-30')
  })

  it('"Voltar para hoje" tira ?dia da URL mesmo partindo de um mês passado', async () => {
    mockDashboard(dashboardData({ balance_date: '2026-09-30' }))

    renderPage('/?mes=2026-09&dia=2026-09-30')

    fireEvent.click(screen.getByText('Saldo em 30/09/2026'))
    await screen.findByTestId('day-picker')
    fireEvent.click(screen.getByText('Voltar para hoje'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-09')
    expect(screen.getByTestId('location')).not.toHaveTextContent('dia=')
  })

  it('"Voltar para hoje" tira ?dia da URL no mês corrente', async () => {
    mockDashboard(dashboardData({ balance_date: '2026-09-15' }))

    renderPage('/?mes=2026-10&dia=2026-09-15')

    fireEvent.click(screen.getByText('Saldo em 15/09/2026'))
    await screen.findByTestId('day-picker')
    fireEvent.click(screen.getByText('Voltar para hoje'))

    expect(screen.getByTestId('location')).toHaveTextContent('?mes=2026-10')
    expect(screen.getByTestId('location')).not.toHaveTextContent('dia=')
  })
})
