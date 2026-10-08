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
// `useTransferSuggestions`/`useOverdueOccurrences`/`useCards` ficam como `vi.fn()` (em vez de
// retorno fixo) para o describe de layout abaixo poder fazer `PendingCard`/`StatementsCard`
// renderizarem conteúdo de verdade — os outros testes desta suíte nunca chegam a essa parte da
// página (sempre com `accounts: []`, que cai no estado vazio antes dela) e por isso não notam a
// diferença.
const useTransferSuggestions = vi.fn()
const useOverdueOccurrences = vi.fn()
const useCards = vi.fn()

vi.mock('@/api/queries/transfer-suggestions', () => ({ useTransferSuggestions: () => useTransferSuggestions() }))
vi.mock('@/api/queries/recurrences', () => ({
  useOverdueOccurrences: () => useOverdueOccurrences(),
  useSkipOccurrence: () => ({ isPending: false, variables: undefined, mutateAsync: vi.fn() }),
  useConfirmOccurrence: () => ({ isPending: false, mutateAsync: vi.fn() }),
}))
vi.mock('@/api/queries/cards', () => ({ useCards: () => useCards() }))
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
  useTransferSuggestions.mockReturnValue({ data: undefined })
  useOverdueOccurrences.mockReturnValue({ data: undefined, isPending: false })
  useCards.mockReturnValue({ data: [] })
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

const account = { id: 1, name: 'Nubank', type: 'checking' as const, currency: 'BRL', color: null, icon: null, balance: 100000 }

const cardWithOpenStatement = {
  id: 1,
  name: 'Nubank Mastercard',
  currency: 'BRL',
  color: null,
  icon: null,
  is_archived: false,
  last_four: '1234',
  credit_limit: 500000,
  closing_day: 10,
  due_day: 17,
  balance: -120000,
  limit: { used: 150000, projected: 0, available: 350000 },
  used_limit: 150000,
  available_limit: 350000,
  current_statement: {
    id: 10,
    account_id: 1,
    closing_date: '2026-10-10',
    due_date: '2026-10-17',
    reported_total: null,
    total: 120000,
    computed_total: 120000,
    paid: 0,
    remaining: 120000,
    status: 'open' as const,
    days_until_due: 14,
    is_overdue: false,
    has_divergence: false,
  },
}

// `PendingCard` e `StatementsCard` (card e array vazio) ficam null por padrão nos testes acima
// (e não chegam a importar — todos os outros casos usam `accounts: []`, que cai no estado vazio
// antes de desenhar qualquer um dos cards); aqui os dois ganham dados de verdade para checar o
// par "Pendências"/"Faturas" lado a lado (ver dashboard-page.tsx).
describe('Início: Pendências e Faturas lado a lado', () => {
  function cardRoot(headingText: string) {
    const heading = screen.getByRole('heading', { name: headingText })
    const card = heading.closest('[data-slot="card"]')
    if (!card) throw new Error(`card não encontrado para "${headingText}"`)
    return card
  }

  it('com as duas, ficam dentro do mesmo par lado a lado (lg:grid-cols-2)', () => {
    mockDashboard(dashboardData({ accounts: [account] }))
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [{ id: 1 }], meta: { next_cursor: null } }] } })
    useCards.mockReturnValue({ data: [cardWithOpenStatement] })

    renderPage('/?mes=2026-10')

    const pendingRoot = cardRoot('Pendências')
    const statementsRoot = cardRoot('Faturas')
    const pair = pendingRoot.parentElement

    expect(pair).toBe(statementsRoot.parentElement)
    expect(pair).toHaveClass('lg:grid-cols-2')
    expect(pair?.children).toHaveLength(2)
  })

  it('só com Faturas (sem pendência nenhuma), o par some e só o card de Faturas fica (volta a ocupar a largura toda)', () => {
    mockDashboard(dashboardData({ accounts: [account] }))
    useCards.mockReturnValue({ data: [cardWithOpenStatement] })

    renderPage('/?mes=2026-10')

    expect(screen.queryByRole('heading', { name: 'Pendências' })).not.toBeInTheDocument()
    const statementsRoot = cardRoot('Faturas')
    expect(statementsRoot.parentElement).toHaveClass('lg:[&>*:only-child]:col-span-2')
    expect(statementsRoot.parentElement?.children).toHaveLength(1)
  })

  it('sem nenhuma das duas (mocks padrão: sem sugestão, sem previstas atrasadas, sem fatura aberta), o par some de vez (empty:hidden, sem sobrar o espaçamento do pai em cima de nada)', () => {
    mockDashboard(dashboardData({ accounts: [account] }))

    const { container } = renderPage('/?mes=2026-10')

    expect(screen.queryByRole('heading', { name: 'Pendências' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Faturas' })).not.toBeInTheDocument()

    const pair = container.querySelector('[class*="empty:hidden"]')
    expect(pair).not.toBeNull()
    expect(pair).toBeEmptyDOMElement()
  })
})
