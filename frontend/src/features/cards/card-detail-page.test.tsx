import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Card, CardStatement } from '@/api/types'
import { CardDetailPage } from './card-detail-page'

let mockCard: Card | undefined
let mockCardIsError = false
let mockCardErrorValue: unknown = null
let mockStatements: CardStatement[] = []
let mockStatementsPending = false
let mockStatementsIsError = false
const refetchCard = vi.fn()
const refetchStatements = vi.fn()

vi.mock('@/api/queries/cards', () => ({
  useCard: () => ({ data: mockCard, isError: mockCardIsError, error: mockCardErrorValue, refetch: refetchCard }),
  useCardStatements: () => ({
    data: mockStatements,
    isPending: mockStatementsPending,
    isError: mockStatementsIsError,
    refetch: refetchStatements,
  }),
  useUpdateStatement: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/transactions', () => ({
  useTransactions: () => ({
    data: { pages: [{ data: [] }] },
    isPending: false,
    isError: false,
    hasNextPage: false,
    isFetchingNextPage: false,
    isPlaceholderData: false,
    fetchNextPage: vi.fn(),
    refetch: vi.fn(),
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function statement(id: number, overrides: Partial<CardStatement> = {}): CardStatement {
  return {
    id,
    account_id: 1,
    closing_date: '2026-09-10',
    due_date: '2026-09-17',
    reported_total: null,
    total: 10000 * id,
    paid: 1,
    remaining: 10000 * id - 1,
    status: 'open',
    days_until_due: 14,
    has_divergence: false,
    ...overrides,
  }
}

const nubank: Card = {
  id: 1,
  name: 'Nubank',
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
  current_statement: null,
}

/** Mostra a localização atual para asserções sobre a URL (ex.: `?fatura=<id>` gravado). */
function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.pathname + location.search}</div>
}

function renderPage(initialPath = '/cartoes/1') {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialPath]}>
        <LocationProbe />
        <Routes>
          <Route path="/cartoes/:id" element={<CardDetailPage />} />
          <Route path="/cartoes" element={<div>Lista de cartões</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockCard = undefined
  mockCardIsError = false
  mockCardErrorValue = null
  mockStatements = []
  mockStatementsPending = false
  mockStatementsIsError = false
  refetchCard.mockReset()
  refetchStatements.mockReset()
  vi.mocked(toast.error).mockReset()
})

describe('CardDetailPage', () => {
  it('abre na fatura atual, mostrando o total dela', () => {
    const current = statement(3, { due_date: '2026-10-17' })
    mockCard = { ...nubank, current_statement: current }
    mockStatements = [statement(1, { due_date: '2026-08-17' }), statement(2, { due_date: '2026-09-17' }), current]

    renderPage()

    expect(screen.getByText('R$ 300,00')).toBeInTheDocument()
  })

  it('abrindo com ?fatura=1 na URL mostra a fatura 1, não a atual', () => {
    const first = statement(1, { due_date: '2026-08-17' })
    const current = statement(3, { due_date: '2026-10-17' })
    mockCard = { ...nubank, current_statement: current }
    mockStatements = [first, statement(2, { due_date: '2026-09-17' }), current]

    renderPage('/cartoes/1?fatura=1')

    expect(screen.getByText('R$ 100,00')).toBeInTheDocument()
  })

  it('clicar "Fatura anterior" mostra a anterior e grava ?fatura=<id> na URL', async () => {
    const current = statement(3, { due_date: '2026-10-17' })
    const previous = statement(2, { due_date: '2026-09-17' })
    mockCard = { ...nubank, current_statement: current }
    mockStatements = [statement(1, { due_date: '2026-08-17' }), previous, current]

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Fatura anterior' }))

    await waitFor(() => expect(screen.getByText('R$ 200,00')).toBeInTheDocument())
    expect(screen.getByTestId('location')).toHaveTextContent('/cartoes/1?fatura=2')
  })

  it('"Próxima fatura" fica desabilitada na última fatura', () => {
    const current = statement(3, { due_date: '2026-10-17' })
    mockCard = { ...nubank, current_statement: current }
    mockStatements = [statement(1, { due_date: '2026-08-17' }), statement(2, { due_date: '2026-09-17' }), current]

    renderPage()

    expect(screen.getByRole('button', { name: 'Próxima fatura' })).toBeDisabled()
  })

  it('cartão sem faturas mostra "Nenhuma fatura ainda" com link para registrar compra', () => {
    mockCard = { ...nubank, current_statement: null }
    mockStatements = []

    renderPage()

    expect(screen.getByText('Nenhuma fatura ainda')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Registrar compra' })).toHaveAttribute(
      'href',
      '/transacoes/nova?conta=1',
    )
  })

  it('erro ao carregar as faturas mostra estado de erro com "Tentar de novo"', () => {
    mockCard = { ...nubank, current_statement: null }
    mockStatements = []
    mockStatementsIsError = true

    renderPage()

    expect(screen.getByText('Não foi possível carregar as faturas.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetchStatements).toHaveBeenCalled()
  })

  it('cartão inexistente (404) volta para /cartoes com toast de erro', async () => {
    mockCardIsError = true
    mockCardErrorValue = new ApiError(404, 'Não encontrado.')

    renderPage()

    await waitFor(() => expect(screen.getByText('Lista de cartões')).toBeInTheDocument())
    expect(toast.error).toHaveBeenCalledWith('Cartão não encontrado.')
  })

  it('erro 500 ao carregar o cartão não redireciona, mostra tela de erro com retry', () => {
    mockCardIsError = true
    mockCardErrorValue = new ApiError(500, 'Erro inesperado no servidor. Tente novamente.')

    renderPage()

    expect(screen.queryByText('Lista de cartões')).not.toBeInTheDocument()
    expect(toast.error).not.toHaveBeenCalled()
    expect(screen.getByText('Não foi possível carregar o cartão.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetchCard).toHaveBeenCalled()
  })
})
